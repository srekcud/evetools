<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Enum\PveIncomeType;
use App\Exception\EveAuthRequiredException;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Sync\PveSyncService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(PveSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
class PveSyncServiceTest extends TestCase
{
    private EsiClient&Stub $esiClient;
    private TokenManager&Stub $tokenManager;
    private PveIncomeRepository&Stub $incomeRepository;
    private PveExpenseRepository&Stub $expenseRepository;
    private UserPveSettingsRepository&Stub $settingsRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private EntityManagerInterface&MockObject $em;
    private PveSyncService $service;

    /** @var list<array<string, mixed>> Mercure sync payloads, in publication order */
    private array $publishedSyncEvents = [];

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->tokenManager = $this->createStub(TokenManager::class);
        $this->incomeRepository = $this->createStub(PveIncomeRepository::class);
        $this->expenseRepository = $this->createStub(PveExpenseRepository::class);
        $this->settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->publishedSyncEvents = [];
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedSyncEvents[] = json_decode($update->getData(), true, 512, JSON_THROW_ON_ERROR);
            return 'urn:uuid:test';
        });

        $mercurePublisher = new MercurePublisherService($hub, new NullLogger());

        $this->service = new PveSyncService(
            $this->esiClient,
            $this->tokenManager,
            $this->incomeRepository,
            $this->expenseRepository,
            $this->settingsRepository,
            $this->invTypeRepository,
            $this->em,
            new NullLogger(),
            $mercurePublisher,
        );
    }

    // ===========================================
    // syncWalletJournal — deduplication by journal_entry_id
    // ===========================================

    public function testBountyImportedWhenNewJournalEntry(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 100001,
                'ref_type' => 'bounty_prizes',
                'amount' => 5_000_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
        ]);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame(1, $imported);
    }

    public function testBountySkippedWhenJournalEntryAlreadyImported(): void
    {
        $user = $this->createUserWithCharacter(12345);

        // Entry 100001 already imported
        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([100001]);
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 100001,
                'ref_type' => 'bounty_prizes',
                'amount' => 5_000_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
        ]);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame(0, $imported);
    }

    public function testEssEntryImportedWithCorrectType(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 100002,
                'ref_type' => 'ess_escrow_transfer',
                'amount' => 2_000_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
        ]);

        $persistedIncome = null;
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedIncome): void {
                $persistedIncome = $entity;
            });
        $this->em->expects($this->once())->method('flush');

        $this->service->syncWalletJournal($user);

        $this->assertNotNull($persistedIncome);
        $this->assertSame(PveIncomeType::Ess, $persistedIncome->getType());
        $this->assertSame(2_000_000.0, $persistedIncome->getAmount());
    }

    public function testMissionRewardImported(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 100003,
                'ref_type' => 'agent_mission_reward',
                'amount' => 1_500_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
        ]);

        $persistedIncome = null;
        $this->em->expects($this->once())->method('persist')
            ->willReturnCallback(function ($entity) use (&$persistedIncome): void {
                $persistedIncome = $entity;
            });
        $this->em->expects($this->once())->method('flush');

        $this->service->syncWalletJournal($user);

        $this->assertNotNull($persistedIncome);
        $this->assertSame(PveIncomeType::Mission, $persistedIncome->getType());
    }

    public function testIrrelevantRefTypeSkipped(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 100004,
                'ref_type' => 'market_escrow',
                'amount' => 10_000_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
        ]);

        $this->em->expects($this->never())->method('persist');

        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame(0, $imported);
    }

    public function testNegativeAmountSkipped(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 100005,
                'ref_type' => 'bounty_prizes',
                'amount' => -1_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
        ]);

        $this->em->expects($this->never())->method('persist');

        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame(0, $imported);
    }

    public function testOldEntryBeyondSyncWindowSkipped(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 100006,
                'ref_type' => 'bounty_prizes',
                'amount' => 5_000_000.0,
                'date' => (new \DateTimeImmutable('-60 days'))->format('c'),
            ],
        ]);

        $this->em->expects($this->never())->method('persist');

        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame(0, $imported);
    }

    public function testMultipleNewEntriesAllImported(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 200001,
                'ref_type' => 'bounty_prizes',
                'amount' => 3_000_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
            [
                'id' => 200002,
                'ref_type' => 'ess_escrow_transfer',
                'amount' => 1_000_000.0,
                'date' => (new \DateTimeImmutable('-2 days'))->format('c'),
            ],
        ]);

        $this->em->expects($this->exactly(2))->method('persist');
        $this->em->expects($this->once())->method('flush');

        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame(2, $imported);
    }

    public function testDuplicateJournalIdInSameBatchImportedOnlyOnce(): void
    {
        $user = $this->createUserWithCharacter(12345);

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);
        // ESI returns the same entry ID twice (edge case with multiple characters)
        $this->esiClient->method('getPaginated')->willReturn([
            [
                'id' => 300001,
                'ref_type' => 'bounty_prizes',
                'amount' => 5_000_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
            [
                'id' => 300001,
                'ref_type' => 'bounty_prizes',
                'amount' => 5_000_000.0,
                'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            ],
        ]);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame(1, $imported);
    }

    public function testCharacterWithoutTokenSkipped(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn(null);

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);

        // ESI client should not be called since no character has a token
        // The stub's default behavior (returning default values) won't cause issues
        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame(0, $imported);
    }

    // ===========================================
    // syncWalletJournal — pagination (issue #11)
    // ===========================================

    public function testBountyOnSecondWalletJournalPageImported(): void
    {
        $user = $this->createUserWithCharacter(12345);
        $this->incomeRepository->method('getImportedJournalEntryIds')->willReturn([]);

        $journalPage1 = [[
            'id' => 400001,
            'ref_type' => 'bounty_prizes',
            'amount' => 4_000_000.0,
            'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
        ]];
        $journalPage2 = [[
            'id' => 400002,
            'ref_type' => 'bounty_prizes',
            'amount' => 6_000_000.0,
            'date' => (new \DateTimeImmutable('-3 days'))->format('c'),
        ]];
        // get() only ever sees page 1 (no X-Pages handling); getPaginated() merges all pages
        $this->esiClient->method('get')->willReturn($journalPage1);
        $this->esiClient->method('getPaginated')->willReturn([...$journalPage1, ...$journalPage2]);

        $persistedJournalEntryIds = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $income) use (&$persistedJournalEntryIds): void {
                $persistedJournalEntryIds[] = $income->getJournalEntryId();
            }
        );

        $imported = $this->service->syncWalletJournal($user);

        $this->assertSame([400001, 400002], $persistedJournalEntryIds);
        $this->assertSame(2, $imported);
    }

    // ===========================================
    // syncLootFromContracts — pagination (issue #11)
    // ===========================================

    public function testLootContractOnSecondContractsPageImported(): void
    {
        $eveCharacterId = 12345;
        $user = $this->createUserWithCharacter($eveCharacterId);
        $this->incomeRepository->method('getImportedContractIds')->willReturn([]);
        $this->settingsRepository->method('findByUser')->willReturn(null);

        $lootTypeId = \App\Entity\UserPveSettings::PVE_LOOT_TYPE_IDS[0];
        $contractsPage1 = [$this->finishedLootContract(500001, $eveCharacterId, 10_000_000.0)];
        $contractsPage2 = [$this->finishedLootContract(500002, $eveCharacterId, 20_000_000.0)];

        $this->esiClient->method('get')->willReturnCallback(
            function (string $endpoint) use ($eveCharacterId, $contractsPage1, $lootTypeId): array {
                if (str_ends_with($endpoint, '/items/')) {
                    return [['type_id' => $lootTypeId, 'quantity' => 3, 'is_included' => true]];
                }
                if ($endpoint === "/characters/{$eveCharacterId}/contracts/") {
                    // ESI without page param returns page 1 only
                    return $contractsPage1;
                }
                return [];
            }
        );
        $this->esiClient->method('getPaginated')->willReturn([...$contractsPage1, ...$contractsPage2]);

        $persistedContractIds = [];
        $this->em->method('persist')->willReturnCallback(
            function (object $income) use (&$persistedContractIds): void {
                $persistedContractIds[] = $income->getContractId();
            }
        );

        $imported = $this->service->syncLootFromContracts($user);

        $this->assertSame([500001, 500002], $persistedContractIds);
        $this->assertSame(2, $imported);
    }

    // ===========================================
    // shouldSync — timing logic
    // ===========================================

    public function testShouldSyncReturnsFalseWhenNoSettings(): void
    {
        $user = $this->createStub(User::class);
        $this->settingsRepository->method('findByUser')->willReturn(null);

        $this->assertFalse($this->service->shouldSync($user));
    }

    public function testShouldSyncReturnsFalseWhenAutoSyncDisabled(): void
    {
        $user = $this->createStub(User::class);
        $settings = new \App\Entity\UserPveSettings();
        $settings->setAutoSyncEnabled(false);
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        $this->assertFalse($this->service->shouldSync($user));
    }

    public function testShouldSyncReturnsTrueWhenNeverSynced(): void
    {
        $user = $this->createStub(User::class);
        $settings = new \App\Entity\UserPveSettings();
        $settings->setAutoSyncEnabled(true);
        // lastSyncAt is null by default
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        $this->assertTrue($this->service->shouldSync($user));
    }

    public function testShouldSyncReturnsFalseWhenRecentlySynced(): void
    {
        $user = $this->createStub(User::class);
        $settings = new \App\Entity\UserPveSettings();
        $settings->setAutoSyncEnabled(true);
        $settings->setLastSyncAt(new \DateTimeImmutable('-5 minutes'));
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        $this->assertFalse($this->service->shouldSync($user));
    }

    public function testShouldSyncReturnsTrueWhenSyncIntervalElapsed(): void
    {
        $user = $this->createStub(User::class);
        $settings = new \App\Entity\UserPveSettings();
        $settings->setAutoSyncEnabled(true);
        $settings->setLastSyncAt(new \DateTimeImmutable('-20 minutes'));
        $this->settingsRepository->method('findByUser')->willReturn($settings);

        $this->assertTrue($this->service->shouldSync($user));
    }

    // ===========================================
    // canSync — token availability
    // ===========================================

    public function testCanSyncReturnsTrueWhenCharacterHasToken(): void
    {
        $user = $this->createStub(User::class);
        $token = $this->createStub(EveToken::class);
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn($token);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        $this->assertTrue($this->service->canSync($user));
    }

    public function testCanSyncReturnsFalseWhenNoCharacterHasToken(): void
    {
        $user = $this->createStub(User::class);
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn(null);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        $this->assertFalse($this->service->canSync($user));
    }

    // ===========================================
    // syncAll — failure reporting (issue #12)
    // ===========================================

    private const PREVIOUS_PVE_SYNC_AT = '2026-01-01 00:00:00';
    private const FAILING_CHARACTER_ID = 22222;
    private const STEP_JOURNAL = 'journal';
    private const STEP_LOOT_SALES = 'lootSales';
    private const STEP_LOOT_CONTRACTS = 'lootContracts';
    private const STEP_EXPENSES = 'expenses';

    public function testSyncAllPublishesSyncErrorAndKeepsLastSyncWhenEveryCharacterFails(): void
    {
        $user = $this->createUserWithOkAndFailingCharacters();
        $this->esiClient->method('get')->willThrowException(new \RuntimeException('ESI 502 Bad Gateway'));
        $this->esiClient->method('getPaginated')->willThrowException(new \RuntimeException('ESI 502 Bad Gateway'));
        $settings = $this->settingsSyncedAt(self::PREVIOUS_PVE_SYNC_AT);

        $this->syncAllIgnoringException($user);

        $this->assertNotContains('completed', $this->publishedStatuses());
        $this->assertSame('error', $this->lastSyncEvent()['status']);
        $this->assertSame('Sync failed for all 2 characters', $this->lastSyncEvent()['message']);
        $this->assertEquals(new \DateTimeImmutable(self::PREVIOUS_PVE_SYNC_AT), $settings->getLastSyncAt());
    }

    public function testSyncAllReportsFailedCharacterCountWhenOneCharacterFails(): void
    {
        $user = $this->createUserWithOkAndFailingCharacters();
        $this->stubEsiFailingFor(self::FAILING_CHARACTER_ID);
        $settings = $this->settingsSyncedAt(self::PREVIOUS_PVE_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncAll($user);

        $this->assertSame(['started', 'in_progress', 'in_progress', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('1 bounties, 0 sales, 0 contracts, 0 expenses (1 of 2 characters failed)', $completed['message']);
        $this->assertSame(1, $completed['data']['bounties']);
        $this->assertSame(1, $completed['data']['failedCharacters']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastSyncAt());
    }

    public function testSyncAllCountsRevokedCharacterAsFailedAndSyncsTheOthers(): void
    {
        $user = $this->createUserWithOkAndFailingCharacters(failingTokenExpiringSoon: true);
        // Only a token due for refresh reaches EVE SSO, which reports the authorization as revoked
        $this->tokenManager->method('getValidAccessToken')->willReturnCallback(
            static fn (EveToken $token): string => $token->isExpiringSoon()
                ? throw new EveAuthRequiredException((string) self::FAILING_CHARACTER_ID)
                : 'valid-access-token',
        );
        $this->stubEsiFailingFor(null);
        $settings = $this->settingsSyncedAt(self::PREVIOUS_PVE_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $result = $this->service->syncAll($user);

        $this->assertSame(1, $result['bounties']);
        $this->assertSame(['started', 'in_progress', 'in_progress', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('1 bounties, 0 sales, 0 contracts, 0 expenses (1 of 2 characters failed)', $completed['message']);
        $this->assertSame(1, $completed['data']['failedCharacters']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastSyncAt());
    }

    public function testSyncAllPublishesPlainCompletedMessageWhenEveryCharacterSucceeds(): void
    {
        $user = $this->createUserWithCharacter(11111);
        $this->stubEsiFailingFor(null);
        $settings = $this->settingsSyncedAt(self::PREVIOUS_PVE_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncAll($user);

        $this->assertSame(['started', 'in_progress', 'in_progress', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('1 bounties, 0 sales, 0 contracts, 0 expenses', $completed['message']);
        $this->assertSame(1, $completed['data']['bounties']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastSyncAt());
    }

    public function testSyncAllPublishesCompletedWithoutFailureWhenNoCharacterHasToken(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $characterWithoutToken = $this->createStub(Character::class);
        $characterWithoutToken->method('getEveToken')->willReturn(null);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$characterWithoutToken]));
        $settings = $this->settingsSyncedAt(self::PREVIOUS_PVE_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncAll($user);

        $this->assertSame(['started', 'in_progress', 'in_progress', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('0 bounties, 0 sales, 0 contracts, 0 expenses', $completed['message']);
        $this->assertSame(0, $completed['data']['failedCharacters']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastSyncAt());
    }

    public static function pveSyncSteps(): iterable
    {
        yield 'wallet journal (bounties)' => [[self::STEP_JOURNAL]];
        yield 'loot sales' => [[self::STEP_LOOT_SALES]];
        yield 'loot contracts' => [[self::STEP_LOOT_CONTRACTS]];
        yield 'expenses' => [[self::STEP_EXPENSES]];
    }

    /** @param list<string> $failingSteps */
    #[DataProvider('pveSyncSteps')]
    public function testSyncAllCountsCharacterFailingOnASingleStepAsFailed(array $failingSteps): void
    {
        $this->assertCharacterFailingOnStepsCountedOnce($failingSteps);
    }

    public function testSyncAllCountsCharacterFailingOnSeveralStepsOnlyOnce(): void
    {
        $this->assertCharacterFailingOnStepsCountedOnce([self::STEP_JOURNAL, self::STEP_LOOT_CONTRACTS, self::STEP_EXPENSES]);
    }

    /** @param list<string> $failingSteps */
    private function assertCharacterFailingOnStepsCountedOnce(array $failingSteps): void
    {
        $user = $this->createUserWithOkAndFailingCharacters();
        $this->stubEsiFailingOnStepsFor(self::FAILING_CHARACTER_ID, $failingSteps);
        $settings = $this->settingsSyncedAt(self::PREVIOUS_PVE_SYNC_AT);
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncAll($user);

        $this->assertSame(['started', 'in_progress', 'in_progress', 'in_progress', 'in_progress', 'completed'], $this->publishedStatuses());
        $completed = $this->lastSyncEvent();
        $this->assertSame('1 bounties, 0 sales, 0 contracts, 0 expenses (1 of 2 characters failed)', $completed['message']);
        $this->assertSame(1, $completed['data']['failedCharacters']);
        $this->assertGreaterThanOrEqual($syncStartedAt, $settings->getLastSyncAt());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createUserWithCharacter(int $eveCharacterId): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $token = $this->createStub(EveToken::class);
        $token->method('isExpiringSoon')->willReturn(false);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('TestChar');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $user;
    }

    /** @return array<string, mixed> */
    private function finishedLootContract(int $contractId, int $issuerId, float $price): array
    {
        return [
            'contract_id' => $contractId,
            'type' => 'item_exchange',
            'status' => 'finished',
            'issuer_id' => $issuerId,
            'price' => $price,
            'date_issued' => (new \DateTimeImmutable('-3 days'))->format('c'),
            'date_completed' => (new \DateTimeImmutable('-2 days'))->format('c'),
        ];
    }

    private function createUserWithOkAndFailingCharacters(bool $failingTokenExpiringSoon = false): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $okToken = $this->createStub(EveToken::class);
        $okToken->method('isExpiringSoon')->willReturn(false);
        $failingToken = $this->createStub(EveToken::class);
        $failingToken->method('isExpiringSoon')->willReturn($failingTokenExpiringSoon);

        $charOk = $this->createStub(Character::class);
        $charOk->method('getEveCharacterId')->willReturn(11111);
        $charOk->method('getEveToken')->willReturn($okToken);
        $charOk->method('getName')->willReturn('CharOk');

        $charFail = $this->createStub(Character::class);
        $charFail->method('getEveCharacterId')->willReturn(self::FAILING_CHARACTER_ID);
        $charFail->method('getEveToken')->willReturn($failingToken);
        $charFail->method('getName')->willReturn('CharFail');

        $user->method('getCharacters')->willReturn(new ArrayCollection([$charFail, $charOk]));

        return $user;
    }

    /** Settings with loot and ammo types, so that every sync step calls ESI for every character */
    private function settingsSyncedAt(string $lastSyncAt): UserPveSettings
    {
        $settings = new UserPveSettings();
        $settings->setLootTypeIds([UserPveSettings::PVE_LOOT_TYPE_IDS[0]]);
        $settings->setAmmoTypeIds([21898]);
        $settings->setLastSyncAt(new \DateTimeImmutable($lastSyncAt));
        $this->settingsRepository->method('findByUser')->willReturn($settings);
        $this->settingsRepository->method('getOrCreate')->willReturn($settings);

        return $settings;
    }

    /** Every endpoint of $failingEveCharacterId throws; the others return one bounty and nothing else */
    private function stubEsiFailingFor(?int $failingEveCharacterId): void
    {
        $respond = function (string $endpoint) use ($failingEveCharacterId): array {
            if ($failingEveCharacterId !== null && str_contains($endpoint, "/characters/{$failingEveCharacterId}/")) {
                throw new \RuntimeException('ESI 502 Bad Gateway');
            }
            if (str_ends_with($endpoint, '/wallet/journal/')) {
                return [[
                    'id' => 600001,
                    'ref_type' => 'bounty_prizes',
                    'amount' => 5_000_000.0,
                    'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
                ]];
            }
            return [];
        };
        $this->esiClient->method('get')->willReturnCallback($respond);
        $this->esiClient->method('getPaginated')->willReturnCallback($respond);
    }

    /**
     * Only the given steps throw for $failingEveCharacterId; every other call answers like stubEsiFailingFor(null).
     * Loot sales and expenses share the wallet transactions endpoint: the first call is loot sales, the second expenses.
     *
     * @param list<string> $failingSteps
     */
    private function stubEsiFailingOnStepsFor(int $failingEveCharacterId, array $failingSteps): void
    {
        $failingCharacterTransactionCalls = 0;
        $respond = function (string $endpoint) use ($failingEveCharacterId, $failingSteps, &$failingCharacterTransactionCalls): array {
            $failingCharacterPrefix = "/characters/{$failingEveCharacterId}/";
            $step = match (true) {
                str_ends_with($endpoint, '/wallet/journal/') => self::STEP_JOURNAL,
                str_ends_with($endpoint, '/contracts/') => self::STEP_LOOT_CONTRACTS,
                str_ends_with($endpoint, '/wallet/transactions/') && str_starts_with($endpoint, $failingCharacterPrefix)
                    => ++$failingCharacterTransactionCalls === 1 ? self::STEP_LOOT_SALES : self::STEP_EXPENSES,
                default => null,
            };
            if (str_starts_with($endpoint, $failingCharacterPrefix) && in_array($step, $failingSteps, true)) {
                throw new \RuntimeException('ESI 502 Bad Gateway');
            }
            if ($step === self::STEP_JOURNAL) {
                return [[
                    'id' => 600001,
                    'ref_type' => 'bounty_prizes',
                    'amount' => 5_000_000.0,
                    'date' => (new \DateTimeImmutable('-1 day'))->format('c'),
                ]];
            }
            return [];
        };
        $this->esiClient->method('get')->willReturnCallback($respond);
        $this->esiClient->method('getPaginated')->willReturnCallback($respond);
    }

    /** The outcome is asserted through Mercure and settings; whether syncAll rethrows is left open */
    private function syncAllIgnoringException(User $user): void
    {
        try {
            $this->service->syncAll($user);
        } catch (\Throwable) {
        }
    }

    /** @return list<string> */
    private function publishedStatuses(): array
    {
        return array_column($this->publishedSyncEvents, 'status');
    }

    /** @return array<string, mixed> */
    private function lastSyncEvent(): array
    {
        $this->assertNotEmpty($this->publishedSyncEvents);

        return $this->publishedSyncEvents[array_key_last($this->publishedSyncEvents)];
    }
}
