<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\PveExpense;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Pve\ScanContractsProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(ScanContractsProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
#[Group('issue-115')]
class ScanContractsProcessorTest extends TestCase
{
    private const EVE_CHARACTER_ID = 2113000001;
    private const CONCORD_ROGUE_ANALYSIS_BEACON = 60244;
    private const SCOURGE_FURY_HEAVY_MISSILE = 2629;
    private const AMMO_MISSING_FROM_SDE = 9848;
    private const RECEIVED_AMMO_CONTRACT_ID = 710001;
    private const BEACON_AND_AMMO_CONTRACT_ID = 710002;

    public function testContractReceivingAmmoMissingFromTheSdeIsDetectedWithTheExplicitLabel(): void
    {
        $contractDate = new \DateTimeImmutable('-3 days');
        $processor = $this->createProcessor(
            [$this->finishedContractAccepted(self::RECEIVED_AMMO_CONTRACT_ID, 1840000.0, $contractDate)],
            [self::RECEIVED_AMMO_CONTRACT_ID => [['type_id' => self::AMMO_MISSING_FROM_SDE, 'quantity' => 4, 'is_included' => true]]],
            [],
        );

        $result = $processor->process(null, new Post());

        $this->assertCount(1, $result->detectedExpenses);
        $expense = $result->detectedExpenses[0];
        $this->assertSame(self::RECEIVED_AMMO_CONTRACT_ID, $expense->contractId);
        $this->assertSame(0, $expense->transactionId);
        $this->assertSame(PveExpense::TYPE_AMMO, $expense->type);
        $this->assertSame(self::AMMO_MISSING_FROM_SDE, $expense->typeId);
        $this->assertSame('4x type 9848 (name missing from the SDE)', $expense->typeName);
        $this->assertSame(4, $expense->quantity);
        $this->assertSame(1840000.0, $expense->price);
        $this->assertSame($contractDate->format('Y-m-d'), $expense->dateIssued);
        $this->assertSame('contract', $expense->source);
    }

    public function testContractMixingAKnownBeaconAndAmmoMissingFromTheSdeListsBothInTheSameOrder(): void
    {
        $contractDate = new \DateTimeImmutable('-5 days');
        $processor = $this->createProcessor(
            [$this->finishedContractAccepted(self::BEACON_AND_AMMO_CONTRACT_ID, 63500000.0, $contractDate)],
            [self::BEACON_AND_AMMO_CONTRACT_ID => [
                ['type_id' => self::CONCORD_ROGUE_ANALYSIS_BEACON, 'quantity' => 2, 'is_included' => true],
                ['type_id' => self::AMMO_MISSING_FROM_SDE, 'quantity' => 13, 'is_included' => true],
            ]],
            [],
        );

        $result = $processor->process(null, new Post());

        $this->assertCount(1, $result->detectedExpenses);
        $expense = $result->detectedExpenses[0];
        $this->assertSame(PveExpense::TYPE_CRAB_BEACON, $expense->type);
        $this->assertSame(self::CONCORD_ROGUE_ANALYSIS_BEACON, $expense->typeId);
        $this->assertSame('2x CONCORD Rogue Analysis Beacon, 13x type 9848 (name missing from the SDE)', $expense->typeName);
        $this->assertSame(15, $expense->quantity);
        $this->assertSame(63500000.0, $expense->price);
    }

    public function testMarketPurchaseOfAmmoMissingFromTheSdeIsDetectedWithTheExplicitLabel(): void
    {
        $transactionDate = new \DateTimeImmutable('-2 days');
        $processor = $this->createProcessor([], [], [
            $this->marketPurchase(810001, self::AMMO_MISSING_FROM_SDE, 11, 470.0, $transactionDate),
        ]);

        $result = $processor->process(null, new Post());

        $this->assertCount(1, $result->detectedExpenses);
        $expense = $result->detectedExpenses[0];
        $this->assertSame(0, $expense->contractId);
        $this->assertSame(810001, $expense->transactionId);
        $this->assertSame(PveExpense::TYPE_AMMO, $expense->type);
        $this->assertSame(self::AMMO_MISSING_FROM_SDE, $expense->typeId);
        $this->assertSame('11x type 9848 (name missing from the SDE)', $expense->typeName);
        $this->assertSame(11, $expense->quantity);
        $this->assertSame(5170.0, $expense->price);
        $this->assertSame($transactionDate->format('Y-m-d'), $expense->dateIssued);
        $this->assertSame('market', $expense->source);
    }

    public function testKnownAmmoKeepsItsSdeNameInContractAndMarketExpenses(): void
    {
        $processor = $this->createProcessor(
            [$this->finishedContractAccepted(self::RECEIVED_AMMO_CONTRACT_ID, 2250000.0, new \DateTimeImmutable('-4 days'))],
            [self::RECEIVED_AMMO_CONTRACT_ID => [['type_id' => self::SCOURGE_FURY_HEAVY_MISSILE, 'quantity' => 1500, 'is_included' => true]]],
            [$this->marketPurchase(810002, self::SCOURGE_FURY_HEAVY_MISSILE, 6000, 1235.5, new \DateTimeImmutable('-1 day'))],
        );

        $result = $processor->process(null, new Post());

        $this->assertSame(
            [
                ['1500x Scourge Fury Heavy Missile', 1500, 2250000.0],
                ['6000x Scourge Fury Heavy Missile', 6000, 7413000.0],
            ],
            array_map(
                static fn ($expense): array => [$expense->typeName, $expense->quantity, $expense->price],
                $result->detectedExpenses,
            ),
        );
    }

    private function createProcessor(array $contracts, array $itemsByContractId, array $transactions): ScanContractsProcessor
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createUserWithCharacter());

        $esiClient = $this->createStub(EsiClient::class);
        $esiClient->method('getPaginated')->willReturn($contracts);
        $esiClient->method('get')->willReturnCallback(
            static function (string $endpoint) use ($itemsByContractId, $transactions): array {
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
        $settings->setAmmoTypeIds([self::SCOURGE_FURY_HEAVY_MISSILE, self::AMMO_MISSING_FROM_SDE]);
        $settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $settingsRepository->method('getOrCreate')->willReturn($settings);

        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getImportedContractIds')->willReturn([]);
        $expenseRepository->method('getImportedTransactionIds')->willReturn([]);

        $typesById = [
            self::CONCORD_ROGUE_ANALYSIS_BEACON => $this->createInvType('CONCORD Rogue Analysis Beacon'),
            self::SCOURGE_FURY_HEAVY_MISSILE => $this->createInvType('Scourge Fury Heavy Missile'),
        ];
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(static fn (mixed $typeId): ?InvType => $typesById[$typeId] ?? null);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        return new ScanContractsProcessor(
            $security,
            $esiClient,
            $this->createStub(TokenManager::class),
            $expenseRepository,
            $settingsRepository,
            $invTypeRepository,
            $requestStack,
        );
    }

    private function createInvType(string $typeName): InvType
    {
        $invType = $this->createStub(InvType::class);
        $invType->method('getTypeName')->willReturn($typeName);

        return $invType;
    }

    private function createUserWithCharacter(): User
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getEveToken')->willReturn($this->createStub(EveToken::class));
        $character->method('getName')->willReturn('Ammo Buyer');

        $user = $this->createStub(User::class);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    private function finishedContractAccepted(int $contractId, float $price, \DateTimeImmutable $completedAt): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'item_exchange',
            'status' => 'finished',
            'acceptor_id' => self::EVE_CHARACTER_ID,
            'issuer_id' => 2113000999,
            'price' => $price,
            'date_issued' => $completedAt->modify('-1 day')->format('c'),
            'date_completed' => $completedAt->format('c'),
        ];
    }

    private function marketPurchase(int $transactionId, int $typeId, int $quantity, float $unitPrice, \DateTimeImmutable $date): array
    {
        return [
            'transaction_id' => $transactionId,
            'type_id' => $typeId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'is_buy' => true,
            'date' => $date->format('c'),
        ];
    }
}
