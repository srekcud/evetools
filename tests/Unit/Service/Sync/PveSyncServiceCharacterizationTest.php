<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\PveExpense;
use App\Entity\PveIncome;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Enum\PveIncomeType;
use App\Exception\EveAuthRequiredException;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\PveSyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PveSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
final class PveSyncServiceCharacterizationTest extends TestCase
{
    private const CHARACTER_ID = 90000001;
    private const CHARACTER_NAME = 'Kaelis Varn';
    private const OTHER_ISSUER_ID = 90000777;
    private const JOURNAL_ENDPOINT = '/characters/90000001/wallet/journal/';
    private const TRANSACTIONS_ENDPOINT = '/characters/90000001/wallet/transactions/';
    private const CONTRACTS_ENDPOINT = '/characters/90000001/contracts/';

    private const OPE_TIER_4 = 19403;
    private const OPE_TIER_6 = 19405;
    private const OPE_TIER_8 = 19407;
    private const OPE_TIER_12 = 19410;
    private const ROGUE_DRONE_DATA = 60459;
    private const DRONE_DATA_MISSING_FROM_SDE = 60460;
    private const CUSTOM_LOOT_TYPE = 47975;
    private const TRITANIUM = 34;
    private const EMP_AMMO = 21898;
    private const HEAVY_MISSILE = 2679;

    private const TYPE_NAMES = [
        self::OPE_TIER_4 => "4th Tier Overseer's Personal Effects",
        self::OPE_TIER_6 => "6th Tier Overseer's Personal Effects",
        self::OPE_TIER_8 => "8th Tier Overseer's Personal Effects",
        self::OPE_TIER_12 => "12th Tier Overseer's Personal Effects",
        self::CUSTOM_LOOT_TYPE => 'Triglavian Survey Database',
        self::EMP_AMMO => 'Republic Fleet EMP L',
        self::HEAVY_MISSILE => 'Scourge Heavy Missile',
    ];

    private EsiClient&MockObject $esiClient;
    private TokenManager&Stub $tokenManager;
    private PveIncomeRepository&Stub $incomeRepository;
    private PveExpenseRepository&Stub $expenseRepository;
    private UserPveSettingsRepository&Stub $settingsRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private EveToken&Stub $token;
    private User&Stub $user;
    private PveSyncService $service;

    private array $persisted = [];

    private array $esiCalls = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->tokenManager = $this->createStub(TokenManager::class);
        $this->incomeRepository = $this->createStub(PveIncomeRepository::class);
        $this->expenseRepository = $this->createStub(PveExpenseRepository::class);
        $this->settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(function (int $typeId): ?InvType {
            if (!isset(self::TYPE_NAMES[$typeId])) {
                return null;
            }
            $type = $this->createStub(InvType::class);
            $type->method('getTypeName')->willReturn(self::TYPE_NAMES[$typeId]);

            return $type;
        });

        $this->persisted = [];
        $this->esiCalls = [];
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        $this->token = $this->createStub(EveToken::class);
        $characterWithoutToken = $this->createStub(Character::class);
        $characterWithoutToken->method('getEveCharacterId')->willReturn(90000002);
        $characterWithoutToken->method('getEveToken')->willReturn(null);
        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(self::CHARACTER_ID);
        $character->method('getName')->willReturn(self::CHARACTER_NAME);
        $character->method('getEveToken')->willReturn($this->token);
        $this->user = $this->createStub(User::class);
        $this->user->method('getId')->willReturn(Uuid::v4());
        $this->user->method('getCharacters')->willReturn(new ArrayCollection([$characterWithoutToken, $character]));

        $this->service = new PveSyncService(
            $this->esiClient,
            $this->tokenManager,
            $this->incomeRepository,
            $this->expenseRepository,
            $this->settingsRepository,
            $invTypeRepository,
            $this->entityManager,
            new NullLogger(),
            new MercurePublisherService($this->createStub(HubInterface::class), new NullLogger()),
        );
    }

    public function testWalletJournalPersistsBountyEssAndMissionIncomesWithAmountDateAndJournalEntryId(): void
    {
        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([7100005]);
        $this->respondWith([self::JOURNAL_ENDPOINT => [
            ['id' => 7100005, 'ref_type' => 'bounty_prizes', 'amount' => 3_333_000.0, 'date' => self::daysAgo(1)],
            ['id' => 7100006, 'ref_type' => 'market_escrow', 'amount' => 9_100_000.0, 'date' => self::daysAgo(1)],
            ['id' => 7100007, 'ref_type' => 'bounty_prizes', 'amount' => 0.0, 'date' => self::daysAgo(1)],
            ['id' => 7100008, 'ref_type' => 'bounty_prizes', 'amount' => 5_550_000.0, 'date' => self::daysAgo(45)],
            ['id' => 7100001, 'ref_type' => 'bounty_prizes', 'amount' => 4_318_250.75, 'date' => self::daysAgo(1)],
            ['id' => 7100002, 'ref_type' => 'ess_escrow_transfer', 'amount' => 1_902_640.5, 'date' => self::daysAgo(2)],
            ['id' => 7100003, 'ref_type' => 'agent_mission_reward', 'amount' => 2_745_000.0, 'date' => self::daysAgo(3)],
            ['id' => 7100004, 'ref_type' => 'agent_mission_time_bonus_reward', 'amount' => 613_300.0, 'date' => self::daysAgo(4)],
        ]]);
        $this->entityManager->expects($this->once())->method('flush');

        $imported = $this->service->syncWalletJournal($this->user);

        $this->assertSame(4, $imported);
        $this->assertSame([
            $this->incomeRow(PveIncomeType::Bounty, 'Bounty - Kaelis Varn', 4_318_250.75, self::daysAgo(1), journalEntryId: 7100001),
            $this->incomeRow(PveIncomeType::Ess, 'ESS - Kaelis Varn', 1_902_640.5, self::daysAgo(2), journalEntryId: 7100002),
            $this->incomeRow(PveIncomeType::Mission, 'Mission Reward - Kaelis Varn', 2_745_000.0, self::daysAgo(3), journalEntryId: 7100003),
            $this->incomeRow(PveIncomeType::Mission, 'Mission Bonus - Kaelis Varn', 613_300.0, self::daysAgo(4), journalEntryId: 7100004),
        ], $this->persistedIncomeRows());
    }

    public function testWalletJournalWithoutPveEntryPersistsAndFlushesNothing(): void
    {
        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->respondWith([self::JOURNAL_ENDPOINT => [
            ['id' => 7200001, 'ref_type' => 'player_donation', 'amount' => 25_000_000.0, 'date' => self::daysAgo(1)],
        ]]);
        $this->entityManager->expects($this->never())->method('flush');

        $this->assertSame(0, $this->service->syncWalletJournal($this->user));
        $this->assertSame([], $this->persisted);
    }

    #[Group('issue-115')]
    public function testLootSalesPersistsSellTransactionsOfLootTypesSkippingImportedAndDeclinedLootSales(): void
    {
        $settings = $this->settingsWithLootTypes([self::OPE_TIER_4, self::ROGUE_DRONE_DATA]);
        $settings->addDeclinedLootSaleTransactionId(8200006);
        $settings->addDeclinedTransactionId(8200007);
        $this->incomeRepository->method('getImportedTransactionIds')->willReturn([8200005]);
        $this->respondWith([self::TRANSACTIONS_ENDPOINT => [
            self::transaction(8200008, self::ROGUE_DRONE_DATA, false, 6, 899_000.0, self::daysAgo(40)),
            self::transaction(8200009, self::OPE_TIER_4, false, 9, 0.0, self::daysAgo(1)),
            self::transaction(8200001, self::OPE_TIER_4, false, 7, 1_234_000.5, self::daysAgo(1)),
            self::transaction(8200002, self::ROGUE_DRONE_DATA, false, 3, 911_250.25, self::daysAgo(2)),
            self::transaction(8200003, self::OPE_TIER_4, true, 2, 1_190_000.0, self::daysAgo(1)),
            self::transaction(8200004, self::TRITANIUM, false, 50_000, 4.5, self::daysAgo(1)),
            self::transaction(8200005, self::OPE_TIER_4, false, 5, 1_222_000.0, self::daysAgo(1)),
            self::transaction(8200006, self::ROGUE_DRONE_DATA, false, 8, 905_000.0, self::daysAgo(1)),
            self::transaction(8200007, self::OPE_TIER_4, false, 4, 1_301_500.0, self::daysAgo(3)),
            ['transaction_id' => 8200010, 'type_id' => self::OPE_TIER_4, 'quantity' => 2, 'unit_price' => 1_250_000.0, 'date' => self::daysAgo(1)],
        ]]);
        $this->entityManager->expects($this->once())->method('flush');

        $imported = $this->service->syncLootSalesFromTransactions($this->user);

        $this->assertSame(3, $imported);
        $this->assertSame([
            $this->incomeRow(PveIncomeType::LootSale, "7x 4th Tier Overseer's Personal Effects", 8_638_003.5, self::daysAgo(1), transactionId: 8200001),
            $this->incomeRow(PveIncomeType::LootSale, '3x type 60459 (name missing from the SDE)', 2_733_750.75, self::daysAgo(2), transactionId: 8200002),
            $this->incomeRow(PveIncomeType::LootSale, "4x 4th Tier Overseer's Personal Effects", 5_206_000.0, self::daysAgo(3), transactionId: 8200007),
        ], $this->persistedIncomeRows());
    }

    public function testLootSalesWithoutPveSettingsImportsNothingAndCallsNoEsi(): void
    {
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->esiClient->expects($this->never())->method('get');
        $this->esiClient->expects($this->never())->method('getPaginated');

        $this->assertSame(0, $this->service->syncLootSalesFromTransactions($this->user));
        $this->assertSame([], $this->persisted);
    }

    public function testLootSalesWithoutLootTypesImportsNothingAndCallsNoEsi(): void
    {
        $this->settingsWithLootTypes([]);
        $this->esiClient->expects($this->never())->method('get');
        $this->esiClient->expects($this->never())->method('getPaginated');

        $this->assertSame(0, $this->service->syncLootSalesFromTransactions($this->user));
        $this->assertSame([], $this->persisted);
    }

    #[Group('issue-115')]
    public function testLootContractsPersistsIssuedFinishedContractsWithIncludedLootAndTheirPrice(): void
    {
        $settings = $this->settingsWithLootTypes([self::CUSTOM_LOOT_TYPE]);
        $settings->addDeclinedContractId(9400002);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([9400001]);
        $includedOpe = [['type_id' => self::OPE_TIER_6, 'quantity' => 3, 'is_included' => true]];
        $this->respondWith(
            [self::CONTRACTS_ENDPOINT => [
                self::lootContract(9400001, 21_000_000.0, self::daysAgo(1)),
                self::lootContract(9400002, 22_000_000.0, self::daysAgo(1)),
                ['type' => 'courier'] + self::lootContract(9400003, 23_000_000.0, self::daysAgo(1)),
                ['status' => 'outstanding'] + self::lootContract(9400004, 24_000_000.0, self::daysAgo(1)),
                ['issuer_id' => self::OTHER_ISSUER_ID] + self::lootContract(9400005, 25_000_000.0, self::daysAgo(1)),
                self::lootContract(9400006, 47_300_000.0, self::daysAgo(2)),
                self::lootContract(9400007, 31_000_000.0, self::daysAgo(1)),
                self::lootContract(9400008, 0.0, self::daysAgo(1)),
                ['status' => 'completed', 'date_completed' => null, 'date_issued' => self::daysAgo(6)] + self::lootContract(9400009, 13_750_000.0, self::daysAgo(3)),
                self::lootContract(9400010, 26_000_000.0, self::daysAgo(50)),
                self::lootContract(9400011, 27_000_000.0, self::daysAgo(1)),
            ]],
            [
                9400001 => $includedOpe,
                9400002 => $includedOpe,
                9400003 => $includedOpe,
                9400004 => $includedOpe,
                9400005 => $includedOpe,
                9400006 => [
                    ['type_id' => self::OPE_TIER_6, 'quantity' => 4, 'is_included' => true],
                    ['type_id' => self::ROGUE_DRONE_DATA, 'quantity' => 9, 'is_included' => true],
                    ['type_id' => self::OPE_TIER_6, 'quantity' => 2, 'is_included' => true],
                ],
                9400007 => [
                    ['type_id' => self::OPE_TIER_8, 'quantity' => 11, 'is_included' => false],
                    ['type_id' => self::OPE_TIER_12, 'quantity' => 7],
                ],
                9400008 => [['type_id' => self::OPE_TIER_12, 'quantity' => 1, 'is_included' => true]],
                9400009 => [['type_id' => self::CUSTOM_LOOT_TYPE, 'quantity' => 15, 'is_included' => true]],
                9400010 => $includedOpe,
                9400011 => [['type_id' => self::TRITANIUM, 'quantity' => 80_000, 'is_included' => true]],
            ],
        );
        $this->entityManager->expects($this->once())->method('flush');

        $imported = $this->service->syncLootFromContracts($this->user);

        $this->assertSame(2, $imported);
        $this->assertSame([
            $this->incomeRow(PveIncomeType::LootContract, "6x 6th Tier Overseer's Personal Effects, 9x type 60459 (name missing from the SDE)", 47_300_000.0, self::daysAgo(2), contractId: 9400006),
            $this->incomeRow(PveIncomeType::LootContract, '15x Triglavian Survey Database', 13_750_000.0, self::daysAgo(6), contractId: 9400009),
        ], $this->persistedIncomeRows());
        $this->assertSame([
            '/characters/90000001/contracts/9400006/items/',
            '/characters/90000001/contracts/9400007/items/',
            '/characters/90000001/contracts/9400008/items/',
            '/characters/90000001/contracts/9400009/items/',
            '/characters/90000001/contracts/9400011/items/',
        ], $this->requestedItemEndpoints());
    }

    #[Group('issue-115')]
    public function testLootContractDescriptionLongerThan250CharactersIsTruncatedWithEllipsis(): void
    {
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->respondWith(
            [self::CONTRACTS_ENDPOINT => [self::lootContract(9400030, 61_900_000.0, self::daysAgo(1))]],
            [9400030 => [
                ['type_id' => 19400, 'quantity' => 11, 'is_included' => true],
                ['type_id' => 19401, 'quantity' => 12, 'is_included' => true],
                ['type_id' => 19402, 'quantity' => 13, 'is_included' => true],
                ['type_id' => 19403, 'quantity' => 14, 'is_included' => true],
                ['type_id' => 19404, 'quantity' => 15, 'is_included' => true],
                ['type_id' => 19405, 'quantity' => 16, 'is_included' => true],
                ['type_id' => 19406, 'quantity' => 17, 'is_included' => true],
                ['type_id' => 19407, 'quantity' => 18, 'is_included' => true],
                ['type_id' => 19408, 'quantity' => 19, 'is_included' => true],
                ['type_id' => 19409, 'quantity' => 21, 'is_included' => true],
                ['type_id' => 19410, 'quantity' => 22, 'is_included' => true],
            ]],
        );

        $this->service->syncLootFromContracts($this->user);

        $this->assertSame([
            $this->incomeRow(
                PveIncomeType::LootContract,
                '11x type 19400 (name missing from the SDE), 12x type 19401 (name missing from the SDE), '
                . '13x type 19402 (name missing from the SDE), '
                . "14x 4th Tier Overseer's Personal Effects, 15x type 19404 (name missing from the SDE), "
                . "16x 6th Tier Overseer's Perso...",
                61_900_000.0,
                self::daysAgo(1),
                contractId: 9400030,
            ),
        ], $this->persistedIncomeRows());
    }

    #[Group('issue-115')]
    public function testLootContractKeepsTwoTypesMissingFromSdeAsDistinctLabelledEntries(): void
    {
        $this->settingsWithLootTypes([self::DRONE_DATA_MISSING_FROM_SDE]);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->respondWith(
            [self::CONTRACTS_ENDPOINT => [self::lootContract(9400040, 58_250_000.0, self::daysAgo(2))]],
            [9400040 => [
                ['type_id' => self::ROGUE_DRONE_DATA, 'quantity' => 9, 'is_included' => true],
                ['type_id' => self::DRONE_DATA_MISSING_FROM_SDE, 'quantity' => 2, 'is_included' => true],
                ['type_id' => self::OPE_TIER_8, 'quantity' => 6, 'is_included' => true],
            ]],
        );

        $this->service->syncLootFromContracts($this->user);

        $this->assertSame([
            $this->incomeRow(
                PveIncomeType::LootContract,
                '9x type 60459 (name missing from the SDE), 2x type 60460 (name missing from the SDE), '
                . "6x 8th Tier Overseer's Personal Effects",
                58_250_000.0,
                self::daysAgo(2),
                contractId: 9400040,
            ),
        ], $this->persistedIncomeRows());
    }

    public function testSyncStepsThatImportNothingPersistAndFlushNothing(): void
    {
        $settings = $this->settingsWithLootTypes([self::OPE_TIER_4]);
        $settings->setAmmoTypeIds([self::EMP_AMMO]);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->respondWith(
            [
                self::TRANSACTIONS_ENDPOINT => [self::transaction(8500001, self::TRITANIUM, false, 12_000, 4.25, self::daysAgo(1))],
                self::CONTRACTS_ENDPOINT => [self::lootContract(9500001, 0.0, self::daysAgo(1))],
            ],
            [9500001 => [['type_id' => self::OPE_TIER_4, 'quantity' => 2, 'is_included' => true]]],
        );
        $this->entityManager->expects($this->never())->method('flush');

        $this->assertSame(0, $this->service->syncLootSalesFromTransactions($this->user));
        $this->assertSame(0, $this->service->syncLootFromContracts($this->user));
        $this->assertSame(0, $this->service->syncExpensesFromTransactions($this->user));
        $this->assertSame([], $this->persisted);
    }

    public function testSyncAllReturnsEveryStepCountAndStepErrors(): void
    {
        $settings = $this->settingsWithLootTypes([self::OPE_TIER_4]);
        $settings->setAmmoTypeIds([self::HEAVY_MISSILE]);
        $this->settingsRepository->method('getOrCreate')->willReturn($settings);
        $this->incomeRepository->method('getImportedJournalEntryIds')->willThrowException(new \RuntimeException('database unavailable'));
        $this->incomeRepository->method('getImportedTransactionIds')->willReturn([]);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->expenseRepository->method('getImportedTransactionIds')->willReturn([]);
        $this->respondWith(
            [
                self::JOURNAL_ENDPOINT => [['id' => 7300001, 'ref_type' => 'bounty_prizes', 'amount' => 3_870_000.0, 'date' => self::daysAgo(1)]],
                self::TRANSACTIONS_ENDPOINT => [
                    self::transaction(8400001, self::OPE_TIER_4, false, 2, 1_245_500.0, self::daysAgo(1)),
                    self::transaction(8400002, self::HEAVY_MISSILE, true, 3_000, 405.25, self::daysAgo(2)),
                ],
                self::CONTRACTS_ENDPOINT => [self::lootContract(9600001, 36_400_000.0, self::daysAgo(3))],
            ],
            [9600001 => [['type_id' => self::OPE_TIER_12, 'quantity' => 5, 'is_included' => true]]],
        );

        $results = $this->service->syncAll($this->user);

        $this->assertSame([
            'bounties' => 0,
            'lootSales' => 1,
            'lootContracts' => 1,
            'expenses' => 1,
            'errors' => ['bounties: database unavailable'],
            'failedCharacters' => 0,
            'tokenizedCharacters' => 1,
        ], $results);
    }

    public function testSyncAllFlushesLastSyncAtEvenWhenNothingIsImported(): void
    {
        $settings = $this->settingsWithLootTypes([]);
        $settings->setLastSyncAt(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $this->settingsRepository->method('getOrCreate')->willReturn($settings);
        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->respondWith([self::JOURNAL_ENDPOINT => [], self::CONTRACTS_ENDPOINT => []]);
        $this->entityManager->expects($this->once())->method('flush');
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncAll($this->user);

        $this->assertSame([], $this->persisted);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastSyncAt());
    }

    public function testRevokedTokenStopsEveryStepBeforeAnyEsiCall(): void
    {
        $settings = $this->settingsWithLootTypes([self::OPE_TIER_4]);
        $settings->setAmmoTypeIds([self::HEAVY_MISSILE]);
        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->incomeRepository->method('getImportedTransactionIds')->willReturn([]);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->expenseRepository->method('getImportedTransactionIds')->willReturn([]);
        $this->tokenManager->method('getValidAccessToken')->willThrowException(new EveAuthRequiredException((string) self::CHARACTER_ID));
        $this->respondWith(
            [
                self::JOURNAL_ENDPOINT => [['id' => 7400001, 'ref_type' => 'bounty_prizes', 'amount' => 2_915_000.0, 'date' => self::daysAgo(1)]],
                self::TRANSACTIONS_ENDPOINT => [
                    self::transaction(8600001, self::OPE_TIER_4, false, 3, 1_288_000.0, self::daysAgo(1)),
                    self::transaction(8600002, self::HEAVY_MISSILE, true, 2_200, 397.5, self::daysAgo(2)),
                ],
                self::CONTRACTS_ENDPOINT => [self::lootContract(9700001, 52_600_000.0, self::daysAgo(2))],
            ],
            [9700001 => [['type_id' => self::OPE_TIER_6, 'quantity' => 8, 'is_included' => true]]],
        );

        $this->assertSame(0, $this->service->syncWalletJournal($this->user));
        $this->assertSame(0, $this->service->syncLootSalesFromTransactions($this->user));
        $this->assertSame(0, $this->service->syncLootFromContracts($this->user));
        $this->assertSame(0, $this->service->syncExpensesFromTransactions($this->user));
        $this->assertSame([], $this->esiCalls);
        $this->assertSame([], $this->persisted);
    }

    #[Group('pve-esi-defaults')]
    public function testLootContractWithoutPriceKeyCurrentlySkippedAsZeroPricePveEsiDefaults(): void
    {
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $contractWithoutPrice = self::lootContract(9400040, 1.0, self::daysAgo(1));
        unset($contractWithoutPrice['price']);
        $this->respondWith(
            [self::CONTRACTS_ENDPOINT => [$contractWithoutPrice]],
            [9400040 => [['type_id' => self::OPE_TIER_8, 'quantity' => 6, 'is_included' => true]]],
        );

        $this->assertSame(0, $this->service->syncLootFromContracts($this->user));
        $this->assertSame([], $this->persisted);
    }

    #[Group('pve-esi-defaults')]
    public function testLootSaleWithoutQuantityCurrentlyImportedAsOneUnitAndWithoutUnitPriceCurrentlySkippedPveEsiDefaults(): void
    {
        $this->settingsWithLootTypes([self::OPE_TIER_4]);
        $this->incomeRepository->method('getImportedTransactionIds')->willReturn([]);
        $this->respondWith([self::TRANSACTIONS_ENDPOINT => [
            ['transaction_id' => 8700001, 'type_id' => self::OPE_TIER_4, 'is_buy' => false, 'quantity' => 6, 'date' => self::daysAgo(1)],
            ['transaction_id' => 8700002, 'type_id' => self::OPE_TIER_4, 'is_buy' => false, 'unit_price' => 1_377_000.0, 'date' => self::daysAgo(2)],
        ]]);

        $this->service->syncLootSalesFromTransactions($this->user);

        $this->assertSame([
            $this->incomeRow(PveIncomeType::LootSale, "1x 4th Tier Overseer's Personal Effects", 1_377_000.0, self::daysAgo(2), transactionId: 8700002),
        ], $this->persistedIncomeRows());
    }

    #[Group('pve-esi-defaults')]
    public function testExpenseWithoutQuantityCurrentlyImportedAsOneUnitAndWithoutUnitPriceCurrentlySkippedPveEsiDefaults(): void
    {
        $settings = new UserPveSettings();
        $settings->setAmmoTypeIds([self::EMP_AMMO]);
        $this->settingsRepository->method('findByUser')->willReturn($settings);
        $this->expenseRepository->method('getImportedTransactionIds')->willReturn([]);
        $this->respondWith([self::TRANSACTIONS_ENDPOINT => [
            ['transaction_id' => 8800001, 'type_id' => self::EMP_AMMO, 'is_buy' => true, 'quantity' => 4_400, 'date' => self::daysAgo(1)],
            ['transaction_id' => 8800002, 'type_id' => self::EMP_AMMO, 'is_buy' => true, 'unit_price' => 117.5, 'date' => self::daysAgo(3)],
        ]]);

        $this->service->syncExpensesFromTransactions($this->user);

        $this->assertSame([
            $this->expenseRow('1x Republic Fleet EMP L', 117.5, self::daysAgo(3), 8800002),
        ], $this->persistedExpenseRows());
    }

    #[Group('pve-audit-P3-5')]
    public function testMixedLootContractCurrentlyCountsWholeContractPriceAsLootIncomeP3_5(): void
    {
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->respondWith(
            [self::CONTRACTS_ENDPOINT => [self::lootContract(9400020, 88_000_000.0, self::daysAgo(1))]],
            [9400020 => [
                ['type_id' => self::OPE_TIER_12, 'quantity' => 1, 'is_included' => true],
                ['type_id' => self::TRITANIUM, 'quantity' => 50_000, 'is_included' => true],
            ]],
        );

        $this->service->syncLootFromContracts($this->user);

        $this->assertSame([
            $this->incomeRow(PveIncomeType::LootContract, "1x 12th Tier Overseer's Personal Effects", 88_000_000.0, self::daysAgo(1), contractId: 9400020),
        ], $this->persistedIncomeRows());
    }

    public function testExpensesPersistsBuyTransactionsOfAmmoTypesSkippingImportedAndDeclinedExpenses(): void
    {
        $settings = new UserPveSettings();
        $settings->setAmmoTypeIds([self::EMP_AMMO, self::HEAVY_MISSILE]);
        $settings->addDeclinedTransactionId(8300006);
        $settings->addDeclinedLootSaleTransactionId(8300007);
        $this->settingsRepository->method('findByUser')->willReturn($settings);
        $this->expenseRepository->method('getImportedTransactionIds')->willReturn([8300005]);
        $this->incomeRepository->method('getImportedTransactionIds')->willReturn([8300001]);
        $this->respondWith([self::TRANSACTIONS_ENDPOINT => [
            self::transaction(8300008, self::HEAVY_MISSILE, true, 600, 415.0, self::daysAgo(40)),
            self::transaction(8300009, self::EMP_AMMO, true, 2_500, 0.0, self::daysAgo(1)),
            self::transaction(8300001, self::EMP_AMMO, true, 5_000, 112.25, self::daysAgo(1)),
            self::transaction(8300002, self::HEAVY_MISSILE, true, 1_200, 418.5, self::daysAgo(5)),
            self::transaction(8300003, self::EMP_AMMO, false, 3_000, 120.0, self::daysAgo(1)),
            self::transaction(8300004, self::TRITANIUM, true, 90_000, 3.75, self::daysAgo(1)),
            self::transaction(8300005, self::EMP_AMMO, true, 4_000, 111.0, self::daysAgo(1)),
            self::transaction(8300006, self::HEAVY_MISSILE, true, 900, 421.0, self::daysAgo(1)),
            self::transaction(8300007, self::EMP_AMMO, true, 750, 109.75, self::daysAgo(2)),
            ['transaction_id' => 8300010, 'type_id' => self::EMP_AMMO, 'quantity' => 1_800, 'unit_price' => 113.0, 'date' => self::daysAgo(1)],
        ]]);
        $this->entityManager->expects($this->once())->method('flush');

        $imported = $this->service->syncExpensesFromTransactions($this->user);

        $this->assertSame(3, $imported);
        $this->assertSame([
            $this->expenseRow('5000x Republic Fleet EMP L', 561_250.0, self::daysAgo(1), 8300001),
            $this->expenseRow('1200x Scourge Heavy Missile', 502_200.0, self::daysAgo(5), 8300002),
            $this->expenseRow('750x Republic Fleet EMP L', 82_312.5, self::daysAgo(2), 8300007),
        ], $this->persistedExpenseRows());
    }

    public function testExpensesWithoutPveSettingsImportsNothingAndCallsNoEsi(): void
    {
        $this->settingsRepository->method('findByUser')->willReturn(null);
        $this->esiClient->expects($this->never())->method('get');
        $this->esiClient->expects($this->never())->method('getPaginated');

        $this->assertSame(0, $this->service->syncExpensesFromTransactions($this->user));
        $this->assertSame([], $this->persisted);
    }

    public function testExpensesWithoutAmmoTypesImportsNothingAndCallsNoEsi(): void
    {
        $this->settingsWithLootTypes([self::OPE_TIER_4]);
        $this->esiClient->expects($this->never())->method('get');
        $this->esiClient->expects($this->never())->method('getPaginated');

        $this->assertSame(0, $this->service->syncExpensesFromTransactions($this->user));
        $this->assertSame([], $this->persisted);
    }

    #[Group('pve-audit-P3-6')]
    public function testWalletTransactionsCurrentlyReadAsSinglePageWithoutFromIdByLootSalesAndExpensesP3_6(): void
    {
        $settings = $this->settingsWithLootTypes([self::OPE_TIER_4]);
        $settings->setAmmoTypeIds([self::EMP_AMMO]);
        $this->respondWith([self::TRANSACTIONS_ENDPOINT => []]);

        $this->service->syncLootSalesFromTransactions($this->user);
        $this->service->syncExpensesFromTransactions($this->user);

        $this->assertSame([
            ['get', self::TRANSACTIONS_ENDPOINT, [self::TRANSACTIONS_ENDPOINT, $this->token, []]],
            ['get', self::TRANSACTIONS_ENDPOINT, [self::TRANSACTIONS_ENDPOINT, $this->token, []]],
        ], $this->esiCalls);
    }

    #[Group('pve-audit-P3-8')]
    public function testShouldSyncCurrentlyReturnsFalseWithoutPveSettingsP3_8(): void
    {
        $this->settingsRepository->method('findByUser')->willReturn(null);

        $this->assertFalse($this->service->shouldSync($this->user));
    }

    private function respondWith(array $listsByEndpoint, array $itemsByContractId = []): void
    {
        $respond = function (string $method) use ($listsByEndpoint, $itemsByContractId): \Closure {
            return function (string $endpoint, mixed ...$rest) use ($method, $listsByEndpoint, $itemsByContractId): array {
                $this->esiCalls[] = [$method, $endpoint, [$endpoint, ...$rest]];
                if (str_contains($endpoint, 'from_id')) {
                    return [];
                }
                if (isset($listsByEndpoint[$endpoint])) {
                    return $listsByEndpoint[$endpoint];
                }
                if (preg_match('#^/characters/90000001/contracts/(\d+)/items/$#', $endpoint, $match) === 1
                    && isset($itemsByContractId[(int) $match[1]])) {
                    return $itemsByContractId[(int) $match[1]];
                }
                throw new \LogicException("Unexpected ESI endpoint {$endpoint}");
            };
        };
        $this->esiClient->method('get')->willReturnCallback($respond('get'));
        $this->esiClient->method('getPaginated')->willReturnCallback($respond('getPaginated'));
    }

    private function settingsWithLootTypes(array $lootTypeIds): UserPveSettings
    {
        $settings = new UserPveSettings();
        $settings->setLootTypeIds($lootTypeIds);
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        return $settings;
    }

    private static function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")
            ->setTime(11, 23, 45)
            ->format(\DateTimeInterface::ATOM);
    }

    private static function transaction(int $transactionId, int $typeId, bool $isBuy, int $quantity, float $unitPrice, string $date): array
    {
        return [
            'transaction_id' => $transactionId,
            'type_id' => $typeId,
            'is_buy' => $isBuy,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'date' => $date,
        ];
    }

    private static function lootContract(int $contractId, float $price, string $dateCompleted): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'item_exchange',
            'status' => 'finished',
            'issuer_id' => self::CHARACTER_ID,
            'price' => $price,
            'date_issued' => (new \DateTimeImmutable($dateCompleted))->modify('-1 day')->format(\DateTimeInterface::ATOM),
            'date_completed' => $dateCompleted,
        ];
    }

    private function incomeRow(
        PveIncomeType $type,
        string $description,
        float $amount,
        string $date,
        ?int $journalEntryId = null,
        ?int $transactionId = null,
        ?int $contractId = null,
    ): array {
        return [
            'user' => $this->user,
            'type' => $type,
            'description' => $description,
            'amount' => $amount,
            'date' => $date,
            'journalEntryId' => $journalEntryId,
            'transactionId' => $transactionId,
            'contractId' => $contractId,
        ];
    }

    private function persistedIncomeRows(): array
    {
        return array_map(function (object $income): array {
            $this->assertInstanceOf(PveIncome::class, $income);

            return [
                'user' => $income->getUser(),
                'type' => $income->getType(),
                'description' => $income->getDescription(),
                'amount' => $income->getAmount(),
                'date' => $income->getDate()->format(\DateTimeInterface::ATOM),
                'journalEntryId' => $income->getJournalEntryId(),
                'transactionId' => $income->getTransactionId(),
                'contractId' => $income->getContractId(),
            ];
        }, $this->persisted);
    }

    private function expenseRow(string $description, float $amount, string $date, int $transactionId): array
    {
        return [
            'user' => $this->user,
            'type' => PveExpense::TYPE_AMMO,
            'description' => $description,
            'amount' => $amount,
            'date' => $date,
            'transactionId' => $transactionId,
            'contractId' => null,
        ];
    }

    private function persistedExpenseRows(): array
    {
        return array_map(function (object $expense): array {
            $this->assertInstanceOf(PveExpense::class, $expense);

            return [
                'user' => $expense->getUser(),
                'type' => $expense->getType(),
                'description' => $expense->getDescription(),
                'amount' => $expense->getAmount(),
                'date' => $expense->getDate()->format(\DateTimeInterface::ATOM),
                'transactionId' => $expense->getTransactionId(),
                'contractId' => $expense->getContractId(),
            ];
        }, $this->persisted);
    }

    private function requestedItemEndpoints(): array
    {
        return array_values(array_filter(
            array_column($this->esiCalls, 1),
            static fn (string $endpoint): bool => str_ends_with($endpoint, '/items/'),
        ));
    }
}
