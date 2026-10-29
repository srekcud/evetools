<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Enum\PveIncomeType;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Pve\ScanLootSalesProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(ScanLootSalesProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-115')]
class ScanLootSalesProcessorTest extends TestCase
{
    private const EVE_CHARACTER_ID = 2114000001;
    private const NPC_CORPORATION_ID = 1000125;
    private const ROGUE_DRONE_INFESTATION_DATA = 60459;
    private const FIFTH_TIER_OVERSEERS_PERSONAL_EFFECTS = 19404;
    private const LOOT_SALE_CONTRACT_ID = 720001;

    public function testMarketSaleOfLootMissingFromTheSdeIsDetectedWithTheExplicitLabel(): void
    {
        $transactionDate = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor([
            $this->marketSale(820001, self::ROGUE_DRONE_INFESTATION_DATA, 3, 92000.0, $transactionDate),
        ], [], []);

        $result = $processor->process(null, new Post());

        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(820001, $sale->transactionId);
        $this->assertSame(0, $sale->contractId);
        $this->assertSame(PveIncomeType::LootSale->value, $sale->type);
        $this->assertSame(self::ROGUE_DRONE_INFESTATION_DATA, $sale->typeId);
        $this->assertSame('3x type 60459 (name missing from the SDE)', $sale->typeName);
        $this->assertSame(3, $sale->quantity);
        $this->assertSame(276000.0, $sale->price);
        $this->assertSame($transactionDate->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame('Loot Seller', $sale->characterName);
        $this->assertSame('market', $sale->source);
    }

    public function testContractSaleMixingLootMissingFromTheSdeAndKnownLootListsBothInTheSameOrder(): void
    {
        $contractDate = new \DateTimeImmutable('-4 days');
        $processor = $this->createProcessor(
            [],
            [$this->finishedContractIssued(self::LOOT_SALE_CONTRACT_ID, 1475000.0, $contractDate)],
            [self::LOOT_SALE_CONTRACT_ID => [
                ['type_id' => self::ROGUE_DRONE_INFESTATION_DATA, 'quantity' => 2, 'is_included' => true],
                ['type_id' => self::FIFTH_TIER_OVERSEERS_PERSONAL_EFFECTS, 'quantity' => 5, 'is_included' => true],
            ]],
        );

        $result = $processor->process(null, new Post());

        $this->assertCount(1, $result->detectedSales);
        $sale = $result->detectedSales[0];
        $this->assertSame(-self::LOOT_SALE_CONTRACT_ID, $sale->transactionId);
        $this->assertSame(self::LOOT_SALE_CONTRACT_ID, $sale->contractId);
        $this->assertSame(self::ROGUE_DRONE_INFESTATION_DATA, $sale->typeId);
        $this->assertSame("2x type 60459 (name missing from the SDE), 5x 5th Tier Overseer's Personal Effects", $sale->typeName);
        $this->assertSame(7, $sale->quantity);
        $this->assertSame(1475000.0, $sale->price);
        $this->assertSame($contractDate->format('Y-m-d'), $sale->dateIssued);
        $this->assertSame('contract', $sale->source);
    }

    public function testKnownLootKeepsItsSdeNameInMarketAndContractSales(): void
    {
        $processor = $this->createProcessor(
            [$this->marketSale(820002, self::FIFTH_TIER_OVERSEERS_PERSONAL_EFFECTS, 8, 625000.0, new \DateTimeImmutable('-1 day'))],
            [$this->finishedContractIssued(self::LOOT_SALE_CONTRACT_ID, 3125000.0, new \DateTimeImmutable('-6 days'))],
            [self::LOOT_SALE_CONTRACT_ID => [['type_id' => self::FIFTH_TIER_OVERSEERS_PERSONAL_EFFECTS, 'quantity' => 4, 'is_included' => true]]],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(
            [
                ["8x 5th Tier Overseer's Personal Effects", 8, 5000000.0, 'market'],
                ["4x 5th Tier Overseer's Personal Effects", 4, 3125000.0, 'contract'],
            ],
            array_map(
                static fn ($sale): array => [$sale->typeName, $sale->quantity, $sale->price, $sale->source],
                $result->detectedSales,
            ),
        );
    }

    private function createProcessor(array $transactions, array $contracts, array $itemsByContractId): ScanLootSalesProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createUserWithNpcCorporationCharacter());

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturn($contracts);
        $esiClient->method('get')->willReturnCallback(
            static function (string $endpoint) use ($transactions, $itemsByContractId): array {
                if ($endpoint === '/characters/' . self::EVE_CHARACTER_ID . '/wallet/transactions/') {
                    return $transactions;
                }
                foreach ($itemsByContractId as $contractId => $items) {
                    if ($endpoint === '/characters/' . self::EVE_CHARACTER_ID . '/contracts/' . $contractId . '/items/') {
                        return $items;
                    }
                }

                return [];
            }
        );

        $settings = new UserPveSettings();
        $settings->setLootTypeIds([self::ROGUE_DRONE_INFESTATION_DATA, self::FIFTH_TIER_OVERSEERS_PERSONAL_EFFECTS]);
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn($settings);

        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getImportedTransactionIds')->willReturn([]);

        $typesById = [
            self::FIFTH_TIER_OVERSEERS_PERSONAL_EFFECTS => $this->createInvType("5th Tier Overseer's Personal Effects"),
        ];
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $typesById[$typeId] ?? null);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new ScanLootSalesProcessor(
            security: $security,
            esiClient: $esiClient,
            tokenManager: $this->createStub(TokenManager::class),
            incomeRepository: $incomeRepository,
            settingsRepository: $settingsRepository,
            invTypeRepository: $invTypeRepository,
            requestStack: $requestStack,
            logger: new NullLogger(),
            cache: new ArrayAdapter(),
        );
    }

    private function createInvType(string $typeName): InvType
    {
        $invType = $this->createStub(InvType::class);
        $invType->method('getTypeName')->willReturn($typeName);

        return $invType;
    }

    private function createUserWithNpcCorporationCharacter(): User
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getCorporationId')->willReturn(self::NPC_CORPORATION_ID);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getName')->willReturn('Loot Seller');
        $character->method('isAuthValid')->willReturn(true);

        $user = $this->createStub(User::class);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    private function marketSale(int $transactionId, int $typeId, int $quantity, float $unitPrice, \DateTimeImmutable $date): array
    {
        return [
            'transaction_id' => $transactionId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'is_buy' => false,
            'date' => $date->format('c'),
        ];
    }

    private function finishedContractIssued(int $contractId, float $price, \DateTimeImmutable $completedAt): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'item_exchange',
            'status' => 'finished',
            'issuer_id' => self::EVE_CHARACTER_ID,
            'acceptor_id' => 2114000999,
            'price' => $price,
            'date_issued' => $completedAt->modify('-1 day')->format('c'),
            'date_completed' => $completedAt->format('c'),
        ];
    }
}
