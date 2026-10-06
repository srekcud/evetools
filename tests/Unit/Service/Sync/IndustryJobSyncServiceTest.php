<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryStepJobMatchRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Sync\IndustryJobSyncService;
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
use Symfony\Contracts\Service\ResetInterface;

#[CoversClass(IndustryJobSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
class IndustryJobSyncServiceTest extends TestCase
{
    private EsiClient&MockObject $esiClient;
    private CachedIndustryJobRepository&MockObject $jobRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private EntityManagerInterface&MockObject $em;
    private IndustryStepJobMatchRepository&Stub $jobMatchRepository;
    private NotificationDispatcher&MockObject $notificationDispatcher;
    private IndustryJobSyncService $service;

    protected function setUp(): void
    {
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $this->invTypeRepository = $this->createStub(InvTypeRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->jobMatchRepository = $this->createStub(IndustryStepJobMatchRepository::class);
        $this->notificationDispatcher = $this->createMock(NotificationDispatcher::class);

        $mercurePublisher = new MercurePublisherService(
            $this->createStub(HubInterface::class),
            new NullLogger(),
        );

        $this->service = new IndustryJobSyncService(
            $this->esiClient,
            $this->jobRepository,
            $this->invTypeRepository,
            $this->em,
            new NullLogger(),
            $mercurePublisher,
            $this->jobMatchRepository,
            $this->notificationDispatcher,
        );
    }

    // ===========================================
    // syncCharacterJobs — new job creation
    // ===========================================

    public function testNewJobPersistedWhenNotInDatabase(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('get')->willReturn([
            $this->makeJobData(1001, 12345, 'active'),
        ]);

        $this->jobRepository->method('findByJobId')->willReturn(null);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterJobs($character);
    }

    // ===========================================
    // syncCharacterJobs — deduplication by job_id
    // ===========================================

    public function testExistingJobNotDuplicated(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $existingJob = new CachedIndustryJob();
        $existingJob->setStatus('active');
        $existingJob->setCharacter($character);

        $this->esiClient->method('get')->willReturn([
            $this->makeJobData(1001, 12345, 'active'),
        ]);

        $this->jobRepository->method('findByJobId')
            ->with(1001)
            ->willReturn($existingJob);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        // Should NOT persist a new entity (only update the existing one)
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterJobs($character);
    }

    public function testDuplicateJobIdsInEsiResponseDeduplicatedByJobId(): void
    {
        $character = $this->createCharacterWithUser(12345);

        // Same job_id appears twice (e.g., in personal and corp results)
        $this->esiClient->method('get')->willReturn([
            $this->makeJobData(2001, 12345, 'active'),
            $this->makeJobData(2001, 12345, 'active'),
        ]);

        $this->jobRepository->method('findByJobId')->willReturn(null);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        // Only 1 persist call for the deduplicated job
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterJobs($character);
    }

    // ===========================================
    // syncCharacterJobs — status transition active → ready
    // ===========================================

    public function testStatusTransitionActiveToReady(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $existingJob = new CachedIndustryJob();
        $existingJob->setStatus('active');
        $existingJob->setCharacter($character);

        $this->esiClient->method('get')->willReturn([
            $this->makeJobData(3001, 12345, 'ready'),
        ]);

        $this->jobRepository->method('findByJobId')
            ->with(3001)
            ->willReturn($existingJob);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterJobs($character);

        // Job status should be updated
        $this->assertSame('ready', $existingJob->getStatus());
    }

    public function testNoNotificationWhenJobStaysActive(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $existingJob = new CachedIndustryJob();
        $existingJob->setStatus('active');
        $existingJob->setCharacter($character);

        $this->esiClient->method('get')->willReturn([
            $this->makeJobData(4001, 12345, 'active'),
        ]);

        $this->jobRepository->method('findByJobId')
            ->with(4001)
            ->willReturn($existingJob);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        // No job-completed notification expected
        $this->notificationDispatcher->expects($this->never())->method('dispatch');

        $this->service->syncCharacterJobs($character);
    }

    // ===========================================
    // syncCharacterJobs — corpmate jobs skipped
    // ===========================================

    public function testCorpmateJobsSkipped(): void
    {
        $character = $this->createCharacterWithUser(12345);

        // Job installed by a different character (corpmate, not one of user's characters)
        $this->esiClient->method('get')->willReturn([
            $this->makeJobData(5001, 99999, 'active'),
        ]);

        $this->jobRepository->expects($this->never())->method('findByJobId');

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterJobs($character);
    }

    // ===========================================
    // syncCharacterJobs — character without token
    // ===========================================

    public function testCharacterWithoutTokenDoesNothing(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getEveToken')->willReturn(null);

        $this->esiClient->expects($this->never())->method('get');
        $this->em->expects($this->never())->method('flush');

        $this->service->syncCharacterJobs($character);
    }

    // ===========================================
    // syncCharacterJobs — stale job cleanup
    // ===========================================

    public function testStaleJobMarkedAsDeliveredWhenMissingFromEsi(): void
    {
        $character = $this->createCharacterWithUser(12345);

        // ESI returns no jobs for this character
        $this->esiClient->method('get')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        // A stale job exists in DB: active, endDate in the past
        $staleJob = new CachedIndustryJob();
        $staleJob->setJobId(9001);
        $staleJob->setStatus('active');
        $staleJob->setEndDate(new \DateTimeImmutable('-2 days'));
        $staleJob->setCharacter($character);

        $this->jobRepository->method('findActiveJobsByCharacter')
            ->with($character)
            ->willReturn([$staleJob]);

        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterJobs($character);

        $this->assertSame('delivered', $staleJob->getStatus());
        $this->assertNotNull($staleJob->getCompletedDate());
        $this->assertSame(
            $staleJob->getEndDate()->format('c'),
            $staleJob->getCompletedDate()->format('c'),
        );
    }

    public function testFutureJobNotMarkedAsDeliveredWhenMissingFromEsi(): void
    {
        $character = $this->createCharacterWithUser(12345);

        // ESI returns no jobs for this character
        $this->esiClient->method('get')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        // A job exists in DB: active, endDate in the future (still running)
        $futureJob = new CachedIndustryJob();
        $futureJob->setJobId(9002);
        $futureJob->setStatus('active');
        $futureJob->setEndDate(new \DateTimeImmutable('+2 days'));
        $futureJob->setCharacter($character);

        $this->jobRepository->method('findActiveJobsByCharacter')
            ->with($character)
            ->willReturn([$futureJob]);

        $this->em->expects($this->once())->method('flush');

        $this->service->syncCharacterJobs($character);

        // Job should remain active — endDate is in the future, could be an ESI glitch
        $this->assertSame('active', $futureJob->getStatus());
        $this->assertNull($futureJob->getCompletedDate());
    }

    // ===========================================
    // syncCharacterJobs — stale job cleanup scoped to the synced character (issue #5)
    // ===========================================

    public function testSyncOfCharacterDoesNotMarkAltActiveJobAsDelivered(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);

        // ESI response for A only contains A's job, not B's
        $this->esiClient->method('get')->willReturn([
            $this->makeJobData(7001, 12345, 'active'),
        ]);
        $this->jobRepository->method('findByJobId')->willReturn(null);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        $altJob = $this->makeCachedJob(7002, $characterB, 'active', '-1 hour');
        $this->stubActiveJobsByCharacter([$altJob]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame('active', $altJob->getStatus());
        $this->assertNull($altJob->getCompletedDate());
    }

    public function testSyncOfCharacterStillMarksItsOwnStaleJobAsDeliveredWhenUserHasAlts(): void
    {
        [$characterA] = $this->createCharactersOfSameUser(12345, 67890);

        $this->esiClient->method('get')->willReturn([]);
        $this->jobRepository->method('findByJobId')->willReturn(null);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        $ownStaleJob = $this->makeCachedJob(7003, $characterA, 'active', '-2 days');
        $this->stubActiveJobsByCharacter([$ownStaleJob]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame('delivered', $ownStaleJob->getStatus());
        $this->assertSame(
            $ownStaleJob->getEndDate()->format('c'),
            $ownStaleJob->getCompletedDate()?->format('c'),
        );
    }

    public function testAltJobCompletionNotificationSentWhenAltSyncedAfterMainCharacter(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);
        $user = $characterB->getUser();

        // A's ESI response is empty; B's ESI response reports B's job as ready
        $this->esiClient->method('get')->willReturnCallback(
            fn (string $endpoint): array => str_starts_with($endpoint, '/characters/67890/')
                ? [$this->makeJobData(7002, 67890, 'ready')]
                : [],
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        $altJob = $this->makeCachedJob(7002, $characterB, 'active', '-1 hour');
        $this->jobRepository->method('findByJobId')
            ->willReturnCallback(fn (int $jobId): ?CachedIndustryJob => $jobId === 7002 ? $altJob : null);
        $this->stubActiveJobsByCharacter([$altJob]);

        $this->notificationDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $user,
                Notification::CATEGORY_INDUSTRY,
                Notification::LEVEL_SUCCESS,
                'Job completed: Type #1000',
                'Type #1000 Manufacturing (5x) ready to deliver',
                [
                    'jobId' => 7002,
                    'productTypeId' => 1000,
                    'productName' => 'Type #1000',
                    'runs' => 5,
                    'activityId' => 1,
                ],
                '/industry',
            );

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);

        $this->assertSame('ready', $altJob->getStatus());
    }

    public function testCorporationJobSeenDuringOtherCharacterSyncIsNotMarkedDelivered(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);

        // Personal endpoints of A and B return nothing; the corporation endpoint (called once,
        // during A's sync) returns a job installed by B
        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->expects($this->once())->method('getPaginated')->willReturn([
            $this->makeJobData(8001, 67890, 'active'),
        ]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        $corporationJobOfB = $this->makeCachedJob(8001, $characterB, 'active', '-1 hour');
        // Listed after the corporation job: proves the loop keeps going past a skipped job
        $personalStaleJobOfB = $this->makeCachedJob(8002, $characterB, 'active', '-2 days');
        $this->jobRepository->method('findByJobId')
            ->willReturnCallback(fn (int $jobId): ?CachedIndustryJob => $jobId === 8001 ? $corporationJobOfB : null);
        $this->stubActiveJobsByCharacter([$corporationJobOfB, $personalStaleJobOfB]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);

        $this->assertSame('active', $corporationJobOfB->getStatus());
        $this->assertNull($corporationJobOfB->getCompletedDate());
        $this->assertSame('delivered', $personalStaleJobOfB->getStatus());
    }

    // ===========================================
    // resetCorporationTracking
    // ===========================================

    public function testResetCorporationTrackingForgetsSeenCorporationJobs(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);

        // Corporation endpoint returns B's job during A's sync, then nothing after the reset
        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->method('getPaginated')->willReturnOnConsecutiveCalls(
            [$this->makeJobData(8001, 67890, 'active')],
            [],
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        $corporationJobOfB = $this->makeCachedJob(8001, $characterB, 'active', '-1 hour');
        $this->jobRepository->method('findByJobId')
            ->willReturnCallback(fn (int $jobId): ?CachedIndustryJob => $jobId === 8001 ? $corporationJobOfB : null);
        $this->stubActiveJobsByCharacter([$corporationJobOfB]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->resetCorporationTracking();
        $this->service->syncCharacterJobs($characterB);

        $this->assertSame('delivered', $corporationJobOfB->getStatus());
        $this->assertSame(
            $corporationJobOfB->getEndDate()->format('c'),
            $corporationJobOfB->getCompletedDate()?->format('c'),
        );
    }

    public function testResetCorporationTrackingMakesNextSyncFetchCorporationJobsAgain(): void
    {
        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->expects($this->exactly(2))
            ->method('getPaginated')
            ->with('/corporations/98000001/industry/jobs/?include_completed=true')
            ->willReturn([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($character);
        $this->service->resetCorporationTracking();
        $this->service->syncCharacterJobs($character);
    }

    public function testCorporationJobsFetchedOnlyOnceForTwoCharactersOfSameCorporationInOneSyncRun(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);

        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->expects($this->once())
            ->method('getPaginated')
            ->with('/corporations/98000001/industry/jobs/?include_completed=true')
            ->willReturn([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);
    }

    // ===========================================
    // ResetInterface — issue #29: corporation tracking must not outlive one scheduled sync
    // ===========================================

    public function testServiceImplementsResetInterfaceAndResetClearsCorporationTracking(): void
    {
        $this->assertInstanceOf(ResetInterface::class, $this->service);

        $character = $this->createCharacterWithUser(12345);

        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->expects($this->exactly(2))
            ->method('getPaginated')
            ->with('/corporations/98000001/industry/jobs/?include_completed=true')
            ->willReturn([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($character);
        $this->service->reset();
        $this->service->syncCharacterJobs($character);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createCharacterWithUser(int $eveCharacterId): Character
    {
        $user = $this->createStub(User::class);
        $userId = Uuid::v4();
        $user->method('getId')->willReturn($userId);

        $token = $this->createStub(EveToken::class);
        $token->method('isExpiringSoon')->willReturn(false);
        $token->method('hasScope')->willReturn(true);

        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn($eveCharacterId);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('TestChar');
        $character->method('getUser')->willReturn($user);
        $character->method('getCorporationId')->willReturn(98000001);

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        return $character;
    }

    /**
     * @return list<Character> characters sharing one user, in the given order
     */
    private function createCharactersOfSameUser(int ...$eveCharacterIds): array
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $token = $this->createStub(EveToken::class);
        $token->method('isExpiringSoon')->willReturn(false);
        $token->method('hasScope')->willReturn(true);

        $characters = [];
        foreach ($eveCharacterIds as $eveCharacterId) {
            $character = $this->createStub(Character::class);
            $character->method('getEveCharacterId')->willReturn($eveCharacterId);
            $character->method('getEveToken')->willReturn($token);
            $character->method('getName')->willReturn("Char{$eveCharacterId}");
            $character->method('getUser')->willReturn($user);
            $character->method('getCorporationId')->willReturn(98000001);
            $characters[] = $character;
        }

        $user->method('getCharacters')->willReturn(new ArrayCollection($characters));

        return $characters;
    }

    private function makeCachedJob(int $jobId, Character $character, string $status, string $endDate): CachedIndustryJob
    {
        $job = new CachedIndustryJob();
        $job->setJobId($jobId);
        $job->setCharacter($character);
        $job->setStatus($status);
        $job->setEndDate(new \DateTimeImmutable($endDate));

        return $job;
    }

    /**
     * Mimics the real query: jobs of the given character whose status is active or ready.
     *
     * @param list<CachedIndustryJob> $jobs
     */
    private function stubActiveJobsByCharacter(array $jobs): void
    {
        $this->jobRepository->method('findActiveJobsByCharacter')->willReturnCallback(
            fn (Character $character): array => array_values(array_filter(
                $jobs,
                fn (CachedIndustryJob $job): bool => $job->getCharacter() === $character
                    && in_array($job->getStatus(), ['active', 'ready'], true),
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeJobData(int $jobId, int $installerId, string $status): array
    {
        return [
            'job_id' => $jobId,
            'installer_id' => $installerId,
            'activity_id' => 1,
            'blueprint_type_id' => 999,
            'product_type_id' => 1000,
            'runs' => 5,
            'cost' => 100_000.0,
            'status' => $status,
            'facility_id' => 60003760,
            'start_date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            'end_date' => (new \DateTimeImmutable('+1 day'))->format('c'),
        ];
    }
}
