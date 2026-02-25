<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\User;
use App\Enum\PveIncomeType;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
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

    protected function setUp(): void
    {
        $this->esiClient = $this->createStub(EsiClient::class);
        $this->tokenManager = $this->createStub(TokenManager::class);
        $this->incomeRepository = $this->createStub(PveIncomeRepository::class);
        $this->expenseRepository = $this->createStub(PveExpenseRepository::class);
        $this->settingsRepository = $this->createStub(UserPveSettingsRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $mercurePublisher = new MercurePublisherService(
            $this->createStub(HubInterface::class),
            new NullLogger(),
        );

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
        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturn([
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
        $this->esiClient->method('get')->willReturn([
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
}
