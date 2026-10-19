<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Processor\Pve;

use ApiPlatform\Metadata\Post;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\State\Processor\Pve\ScanLootContractsProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ScanLootContractsProcessor::class)]
#[AllowMockObjectsWithoutExpectations]
class ScanLootContractsProcessorTest extends TestCase
{
    private const EVE_CHARACTER_ID = 12345;
    private const ROGUE_DRONE_INFESTATION_DATA = 60459;
    private const THIRD_TIER_OVERSEERS_PERSONAL_EFFECTS = 19402;

    private Security&Stub $security;
    private EsiClient&Stub $esiClient;
    private PveIncomeRepository&Stub $incomeRepository;
    private UserPveSettingsRepository&Stub $settingsRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private ScanLootContractsProcessor $processor;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->incomeRepository = $this->createStub(PveIncomeRepository::class);
        $this->settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $this->processor = new ScanLootContractsProcessor(
            $this->security,
            $this->esiClient,
            $this->createStub(TokenManager::class),
            $this->incomeRepository,
            $this->settingsRepository,
            $this->invTypeRepository,
            $requestStack,
        );
    }

    // ===========================================
    // Contracts pagination (issue #11)
    // ===========================================

    public function testLootContractOnSecondContractsPageDetected(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithCharacter());
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->settingsRepository->method('findByUser')->willReturn(null);

        $lootTypeId = UserPveSettings::PVE_LOOT_TYPE_IDS[0];
        $contractsPage1 = [$this->finishedLootContract(600001)];
        $contractsPage2 = [$this->finishedLootContract(600002)];

        $this->esiClient->method('get')->willReturnCallback(
            function (string $endpoint) use ($contractsPage1, $lootTypeId): array {
                if (str_ends_with($endpoint, '/items/')) {
                    return [['type_id' => $lootTypeId, 'quantity' => 4, 'is_included' => true]];
                }
                if ($endpoint === '/characters/' . self::EVE_CHARACTER_ID . '/contracts/') {
                    // ESI without page param returns page 1 only
                    return $contractsPage1;
                }
                return [];
            }
        );
        $this->esiClient->method('getPaginated')->willReturn([...$contractsPage1, ...$contractsPage2]);

        $result = $this->processor->process(null, new Post());

        $detectedContractIds = array_map(
            static fn ($detected) => $detected->contractId,
            $result->detectedContracts,
        );
        $this->assertSame([600001, 600002], $detectedContractIds);
        $this->assertSame(2, $result->scannedContracts);
    }

    #[Group('issue-115')]
    public function testLootContractWithAnItemMissingFromTheSdeLeavesItsNameUnknownAndLabelsItInTheDescription(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithCharacter());
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->knowOnlyThirdTierOverseersPersonalEffects();
        $this->esiClient->method('getPaginated')->willReturn([$this->finishedLootContractAtPrice(600101, 1260000.0)]);
        $this->esiClient->method('get')->willReturn([
            ['type_id' => self::ROGUE_DRONE_INFESTATION_DATA, 'quantity' => 9, 'is_included' => true],
            ['type_id' => self::THIRD_TIER_OVERSEERS_PERSONAL_EFFECTS, 'quantity' => 6, 'is_included' => true],
        ]);

        $result = $this->processor->process(null, new Post());

        $this->assertCount(1, $result->detectedContracts);
        $detected = $result->detectedContracts[0];
        $this->assertSame(600101, $detected->contractId);
        $this->assertSame([
            ['typeId' => self::ROGUE_DRONE_INFESTATION_DATA, 'typeName' => null, 'quantity' => 9],
            ['typeId' => self::THIRD_TIER_OVERSEERS_PERSONAL_EFFECTS, 'typeName' => "3rd Tier Overseer's Personal Effects", 'quantity' => 6],
        ], $detected->items);
        $this->assertSame("9x type 60459 (name missing from the SDE), 6x 3rd Tier Overseer's Personal Effects", $detected->description);
        $this->assertSame(15, $detected->totalQuantity);
        $this->assertSame(1260000.0, $detected->contractPrice);
        $this->assertSame(1260000.0, $detected->suggestedPrice);
    }

    #[Group('issue-115')]
    public function testLootContractWithOnlyKnownItemsKeepsTheirSdeNames(): void
    {
        $this->security->method('getUser')->willReturn($this->createUserWithCharacter());
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->knowOnlyThirdTierOverseersPersonalEffects();
        $this->esiClient->method('getPaginated')->willReturn([$this->finishedLootContractAtPrice(600102, 0.0)]);
        $this->esiClient->method('get')->willReturn([
            ['type_id' => self::THIRD_TIER_OVERSEERS_PERSONAL_EFFECTS, 'quantity' => 11, 'is_included' => true],
        ]);

        $result = $this->processor->process(null, new Post());

        $this->assertCount(1, $result->detectedContracts);
        $detected = $result->detectedContracts[0];
        $this->assertSame([
            ['typeId' => self::THIRD_TIER_OVERSEERS_PERSONAL_EFFECTS, 'typeName' => "3rd Tier Overseer's Personal Effects", 'quantity' => 11],
        ], $detected->items);
        $this->assertSame("11x 3rd Tier Overseer's Personal Effects", $detected->description);
        $this->assertSame(11, $detected->totalQuantity);
        $this->assertSame(1100000.0, $detected->suggestedPrice);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserWithCharacter(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $token = $this->createStub(EveToken::class);
        $token->method('isExpiringSoon')->willReturn(false);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::EVE_CHARACTER_ID);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('TestChar');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    private function knowOnlyThirdTierOverseersPersonalEffects(): void
    {
        $knownType = $this->createStub(InvType::class);
        $knownType->method('getTypeName')->willReturn("3rd Tier Overseer's Personal Effects");
        $this->invTypeRepository->method('find')->willReturnCallback(
            static fn (mixed $typeId): ?InvType => $typeId === self::THIRD_TIER_OVERSEERS_PERSONAL_EFFECTS ? $knownType : null,
        );
    }

    private function finishedLootContractAtPrice(int $contractId, float $price): array
    {
        return [...$this->finishedLootContract($contractId), 'price' => $price];
    }

    /** @return array<string, mixed> */
    private function finishedLootContract(int $contractId): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'item_exchange',
            'status' => 'finished',
            'issuer_id' => self::EVE_CHARACTER_ID,
            'price' => 0.0,
            'date_issued' => (new \DateTimeImmutable('-3 days'))->format('c'),
            'date_completed' => (new \DateTimeImmutable('-2 days'))->format('c'),
        ];
    }
}
