<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\EveToken;
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
    // resetCorporationTracking
    // ===========================================

    public function testResetCorporationTrackingDoesNotThrow(): void
    {
        // Verify the method is callable without exception
        $this->service->resetCorporationTracking();
        $this->addToAssertionCount(1);
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
