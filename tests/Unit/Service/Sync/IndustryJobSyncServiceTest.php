<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\Notification;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryStepJobMatchRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Sync\IndustryJobSyncService;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

#[CoversClass(IndustryJobSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
class IndustryJobSyncServiceTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int TYPE_ID_MISSING_FROM_SDE = 1000;
    private const string CHARACTER_JOBS_SCOPE = 'esi-industry.read_character_jobs.v1';
    private const string CORPORATION_JOBS_SCOPE = 'esi-industry.read_corporation_jobs.v1';

    private EsiClient&MockObject $esiClient;
    private CachedIndustryJobRepository&MockObject $jobRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private EntityManagerInterface&MockObject $em;
    private IndustryStepJobMatchRepository&Stub $jobMatchRepository;
    private NotificationDispatcher&MockObject $notificationDispatcher;
    private IndustryJobSyncService $service;
    private array $publishedMercurePayloads = [];
    private BufferingLogger $logger;
    private array $persistedJobs = [];
    private int $flushCount = 0;

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $this->invTypeRepository = $this->sdeTypeRepositoryKnowingOnlyNitrogenFuelBlock();
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->jobMatchRepository = $this->createStub(IndustryStepJobMatchRepository::class);
        $this->notificationDispatcher = $this->createMock(NotificationDispatcher::class);

        $mercureHub = $this->createStub(HubInterface::class);
        $mercureHub->method('publish')->willReturnCallback(function (Update $update): string {
            $this->publishedMercurePayloads[] = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);

            return 'published';
        });

        $mercurePublisher = new MercurePublisherService($mercureHub, new NullLogger());

        $this->service = new IndustryJobSyncService(
            $this->esiClient,
            $this->jobRepository,
            $this->invTypeRepository,
            $this->em,
            $this->logger,
            $mercurePublisher,
            $this->jobMatchRepository,
            $this->notificationDispatcher,
        );
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
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
                'Job completed: type 1000 (name missing from the SDE)',
                'type 1000 (name missing from the SDE) Manufacturing (5x) ready to deliver',
                [
                    'jobId' => 7002,
                    'productTypeId' => 1000,
                    'productName' => null,
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

    public function testCompletedJobNotificationNamesKnownProductFromSde(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $user = $character->getUser();
        $this->completeJobOf($character, 7001, self::NITROGEN_FUEL_BLOCK_TYPE_ID);

        $this->notificationDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(
                $user,
                Notification::CATEGORY_INDUSTRY,
                Notification::LEVEL_SUCCESS,
                'Job completed: Nitrogen Fuel Block',
                'Nitrogen Fuel Block Manufacturing (5x) ready to deliver',
                [
                    'jobId' => 7001,
                    'productTypeId' => 4051,
                    'productName' => 'Nitrogen Fuel Block',
                    'runs' => 5,
                    'activityId' => 1,
                ],
                '/industry',
            );

        $this->service->syncCharacterJobs($character);

        $jobCompletedNotifications = $this->mercureNotificationsOf('industry-job-completed');
        $this->assertCount(1, $jobCompletedNotifications);
        $this->assertSame('Nitrogen Fuel Block - Manufacturing completed!', $jobCompletedNotifications[0]['message']);
        $this->assertSame([
            'jobId' => 7001,
            'productTypeId' => 4051,
            'productName' => 'Nitrogen Fuel Block',
            'runs' => 5,
            'activityId' => 1,
        ], $jobCompletedNotifications[0]['data']);
    }

    public function testJobCompletedMercureMessageLabelsProductMissingFromSdeWithNullProductName(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->completeJobOf($character, 7002, self::TYPE_ID_MISSING_FROM_SDE);

        $this->service->syncCharacterJobs($character);

        $jobCompletedNotifications = $this->mercureNotificationsOf('industry-job-completed');
        $this->assertCount(1, $jobCompletedNotifications);
        $this->assertSame(
            'type 1000 (name missing from the SDE) - Manufacturing completed!',
            $jobCompletedNotifications[0]['message'],
        );
        $this->assertSame([
            'jobId' => 7002,
            'productTypeId' => 1000,
            'productName' => null,
            'runs' => 5,
            'activityId' => 1,
        ], $jobCompletedNotifications[0]['data']);
    }

    public function testProjectProgressMercureMessageLabelsStepProductMissingFromSde(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->completeJobOf($character, 7002, self::TYPE_ID_MISSING_FROM_SDE);

        $project = $this->createStub(IndustryProject::class);
        $project->method('getId')->willReturn(Uuid::fromString('0193a5c2-7b1e-7c4d-9f2a-3e8b6d1c5a40'));
        $project->method('getName')->willReturn('Batch');
        $step = (new IndustryProjectStep())->setProject($project)->setProductTypeId(self::TYPE_ID_MISSING_FROM_SDE);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([(new IndustryStepJobMatch())->setStep($step)]);

        $this->service->syncCharacterJobs($character);

        $projectNotifications = $this->mercureNotificationsOf('industry-project');
        $this->assertCount(1, $projectNotifications);
        $this->assertSame(
            'Project "Batch": type 1000 (name missing from the SDE) completed',
            $projectNotifications[0]['message'],
        );
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

    public function testForbiddenCorporationJobsReadMarksNoJobAsDelivered(): void
    {
        [$character, $corporationJob, $personalStaleJob] = $this->givenCharacterWithReadyCorporationJobAndStalePersonalJob();
        $this->esiClient->method('getPaginated')->willThrowException(EsiApiException::forbidden());

        $this->service->syncCharacterJobs($character);

        $this->assertSame('ready', $corporationJob->getStatus());
        $this->assertNull($corporationJob->getCompletedDate());
        $this->assertSame('active', $personalStaleJob->getStatus());
        $this->assertNull($personalStaleJob->getCompletedDate());
    }

    public function testForbiddenCorporationJobsReadStillRecordsPersonalJobsFromEsi(): void
    {
        [$character] = $this->givenCharacterWithReadyCorporationJobAndStalePersonalJob();
        $this->esiClient->method('getPaginated')->willThrowException(EsiApiException::forbidden());

        $this->service->syncCharacterJobs($character);

        $this->assertSame([[101, 7, 'active']], array_map(
            static fn (CachedIndustryJob $job): array => [$job->getJobId(), $job->getRuns(), $job->getStatus()],
            $this->persistedJobs,
        ));
        $this->assertSame(1, $this->flushCount);
    }

    public function testForbiddenCorporationJobsReadLogsOneWarning(): void
    {
        [$character] = $this->givenCharacterWithReadyCorporationJobAndStalePersonalJob();
        $this->esiClient->method('getPaginated')->willThrowException(EsiApiException::forbidden());

        $this->service->syncCharacterJobs($character);

        $this->assertCount(1, $this->loggedWarnings());
    }

    #[DataProvider('corporationJobsReadFailuresOtherThanForbidden')]
    public function testCorporationJobsReadFailureOtherThanForbiddenPropagatesWithoutWriting(\Throwable $corporationJobsReadFailure): void
    {
        [$character, $corporationJob, $personalStaleJob] = $this->givenCharacterWithReadyCorporationJobAndStalePersonalJob();
        $this->esiClient->method('getPaginated')->willThrowException($corporationJobsReadFailure);

        $this->assertSame($corporationJobsReadFailure, $this->syncCatchingFailure($character));
        $this->assertSame(0, $this->flushCount);
        $this->assertSame([], $this->persistedJobs);
        $this->assertSame('ready', $corporationJob->getStatus());
        $this->assertNull($corporationJob->getCompletedDate());
        $this->assertSame('active', $personalStaleJob->getStatus());
        $this->assertNull($personalStaleJob->getCompletedDate());
        $this->assertSame([], $this->loggedWarnings());
    }

    public static function corporationJobsReadFailuresOtherThanForbidden(): iterable
    {
        yield 'E2 service unavailable 503' => [EsiApiException::fromResponse(503, 'Service Unavailable')];
        yield 'E3 error limited 420 after the client retry' => [EsiApiException::fromResponse(420, 'Error limited')];
        yield 'unauthorized 401' => [EsiApiException::unauthorized()];
        yield 'not found 404' => [EsiApiException::notFound()];
        yield 'network error' => [EsiApiException::fromResponse(0, 'Network error: connection reset')];
        yield 'non ESI failure' => [new \UnexpectedValueException('ESI cursor page has no records list')];
    }

    public function testCorporationJobStillPresentInEsiIsNotMarkedDeliveredWhilePersonalStaleJobIs(): void
    {
        [$character, $corporationJob, $personalStaleJob] = $this->givenCharacterWithReadyCorporationJobAndStalePersonalJob();
        $this->esiClient->method('getPaginated')->willReturn([
            $this->makeJobData(900, 12345, 'ready'),
        ]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame('ready', $corporationJob->getStatus());
        $this->assertNull($corporationJob->getCompletedDate());
        $this->assertSame('delivered', $personalStaleJob->getStatus());
        $this->assertSame(
            $personalStaleJob->getEndDate()->format('c'),
            $personalStaleJob->getCompletedDate()?->format('c'),
        );
        $this->assertSame([], $this->loggedWarnings());
    }

    public function testStalePersonalJobMarkedDeliveredWhenCorporationAlreadyReadByAnotherCharacterThisSync(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);
        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->expects($this->once())->method('getPaginated')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJobOfB = $this->makeCachedJob(100, $characterB, 'active', '-3 hours');
        $this->stubActiveJobsByCharacter([$personalStaleJobOfB]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);

        $this->assertSame('delivered', $personalStaleJobOfB->getStatus());
    }

    public function testStalePersonalJobMarkedDeliveredForCharacterWithoutCorporationJobsScope(): void
    {
        $character = $this->createCharacterWithoutCorporationJobsScope(12345);
        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->expects($this->never())->method('getPaginated');
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJob = $this->makeCachedJob(100, $character, 'active', '-3 hours');
        $this->stubActiveJobsByCharacter([$personalStaleJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame('delivered', $personalStaleJob->getStatus());
    }

    public function testStalePersonalJobStaysActiveForCharacterWithoutAnyJobsScope(): void
    {
        [$character] = $this->createCharactersOfSameUserWithScopes([12345 => []]);
        $this->esiClient->expects($this->never())->method('get');
        $this->esiClient->expects($this->never())->method('getPaginated');
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJob = $this->makeCachedJob(100, $character, 'active', '-1 day');
        $this->stubActiveJobsByCharacter([$personalStaleJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame('active', $personalStaleJob->getStatus());
        $this->assertNull($personalStaleJob->getCompletedDate());
    }

    public function testStalePersonalJobStaysActiveWhileCorporationJobIsRecordedForCharacterWithCorporationJobsScopeOnly(): void
    {
        [$character] = $this->createCharactersOfSameUserWithScopes([12345 => [self::CORPORATION_JOBS_SCOPE]]);
        $this->esiClient->expects($this->never())->method('get');
        $corporationJobData = $this->makeJobData(900, 12345, 'ready');
        $corporationJobData['runs'] = 3;
        $this->esiClient->expects($this->once())
            ->method('getPaginated')
            ->with('/corporations/98000001/industry/jobs/?include_completed=true')
            ->willReturn([$corporationJobData]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->jobRepository->method('findByJobId')->willReturn(null);
        $personalStaleJob = $this->makeCachedJob(100, $character, 'active', '-1 day');
        $this->stubActiveJobsByCharacter([$personalStaleJob]);
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedJobs[] = $entity;
        });

        $this->service->syncCharacterJobs($character);

        $this->assertSame('active', $personalStaleJob->getStatus());
        $this->assertNull($personalStaleJob->getCompletedDate());
        $this->assertSame([[900, 3, 'ready']], array_map(
            static fn (CachedIndustryJob $job): array => [$job->getJobId(), $job->getRuns(), $job->getStatus()],
            $this->persistedJobs,
        ));
    }

    public function testStalePersonalJobStaysActiveForCharacterWithCorporationJobsScopeOnlyWhenCorporationAlreadyReadThisSync(): void
    {
        [$characterB, $characterA] = $this->createCharactersOfSameUserWithScopes([
            67890 => [self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE],
            12345 => [self::CORPORATION_JOBS_SCOPE],
        ]);
        $this->esiClient->expects($this->once())
            ->method('get')
            ->with('/characters/67890/industry/jobs/?include_completed=true')
            ->willReturn([]);
        $this->esiClient->expects($this->once())->method('getPaginated')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJobOfA = $this->makeCachedJob(100, $characterA, 'active', '-1 day');
        $this->stubActiveJobsByCharacter([$personalStaleJobOfA]);

        $this->service->syncCharacterJobs($characterB);
        $this->service->syncCharacterJobs($characterA);

        $this->assertSame('active', $personalStaleJobOfA->getStatus());
        $this->assertNull($personalStaleJobOfA->getCompletedDate());
    }

    public function testCorporationJobsReadAgainBySecondCharacterAfterForbiddenRead(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);
        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->expects($this->exactly(2))
            ->method('getPaginated')
            ->with('/corporations/98000001/industry/jobs/?include_completed=true')
            ->willThrowException(EsiApiException::forbidden());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);
    }

    public function testCharacterWithCorporationRolesReadsCorporationJobsAfterForbiddenReadOfAnother(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);
        $this->esiClient->method('get')->willReturn([]);
        $this->esiClient->expects($this->exactly(2))
            ->method('getPaginated')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(EsiApiException::forbidden()),
                [],
            );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJobOfB = $this->makeCachedJob(100, $characterB, 'active', '-3 hours');
        $this->stubActiveJobsByCharacter([$personalStaleJobOfB]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);

        $this->assertSame('delivered', $personalStaleJobOfB->getStatus());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function givenCharacterWithReadyCorporationJobAndStalePersonalJob(): array
    {
        $character = $this->createCharacterWithUser(12345);
        $newPersonalJob = $this->makeJobData(101, 12345, 'active');
        $newPersonalJob['runs'] = 7;
        $this->esiClient->method('get')->willReturn([$newPersonalJob]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);

        $corporationJob = $this->makeCachedJob(900, $character, 'ready', '-5 hours');
        $personalStaleJob = $this->makeCachedJob(100, $character, 'active', '-2 days');
        $this->jobRepository->method('findByJobId')
            ->willReturnCallback(fn (int $jobId): ?CachedIndustryJob => $jobId === 900 ? $corporationJob : null);
        $this->stubActiveJobsByCharacter([$corporationJob, $personalStaleJob]);

        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedJobs[] = $entity;
        });
        $this->em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        return [$character, $corporationJob, $personalStaleJob];
    }

    private function createCharacterWithoutCorporationJobsScope(int $eveCharacterId): Character
    {
        $character = $this->createCharacterWithUser($eveCharacterId);
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturnCallback(
            static fn (string $scope): bool => $scope === 'esi-industry.read_character_jobs.v1',
        );
        $characterWithoutCorporationJobsScope = $this->createStub(Character::class);
        $characterWithoutCorporationJobsScope->method('getEveCharacterId')->willReturn($eveCharacterId);
        $characterWithoutCorporationJobsScope->method('getEveToken')->willReturn($token);
        $characterWithoutCorporationJobsScope->method('getName')->willReturn('NoCorpScopeChar');
        $characterWithoutCorporationJobsScope->method('getUser')->willReturn($character->getUser());
        $characterWithoutCorporationJobsScope->method('getCorporationId')->willReturn(98000001);

        return $characterWithoutCorporationJobsScope;
    }

    private function syncCatchingFailure(Character $character): ?\Throwable
    {
        try {
            $this->service->syncCharacterJobs($character);
        } catch (\Throwable $failure) {
            return $failure;
        }

        return null;
    }

    private function loggedWarnings(): array
    {
        return array_values(array_filter(
            $this->logger->cleanLogs(),
            static fn (array $log): bool => $log[0] === LogLevel::WARNING,
        ));
    }

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
     * @return list<Character>
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

    /**
     * @param array<int, list<string>> $scopesByEveCharacterId
     *
     * @return list<Character>
     */
    private function createCharactersOfSameUserWithScopes(array $scopesByEveCharacterId): array
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());

        $characters = [];
        foreach ($scopesByEveCharacterId as $eveCharacterId => $scopes) {
            $token = $this->createStub(EveToken::class);
            $token->method('isExpiringSoon')->willReturn(false);
            $token->method('hasScope')->willReturnCallback(
                static fn (string $scope): bool => in_array($scope, $scopes, true),
            );

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

    private function completeJobOf(Character $character, int $jobId, int $productTypeId): void
    {
        $this->esiClient->method('get')->willReturn([
            $this->makeJobData($jobId, $character->getEveCharacterId(), 'ready', $productTypeId),
        ]);
        $activeJob = $this->makeCachedJob($jobId, $character, 'active', '-1 hour');
        $this->jobRepository->method('findByJobId')
            ->willReturnCallback(fn (int $requestedJobId): ?CachedIndustryJob => $requestedJobId === $jobId ? $activeJob : null);
        $this->stubActiveJobsByCharacter([$activeJob]);
    }

    private function mercureNotificationsOf(string $syncType): array
    {
        return array_values(array_filter(
            $this->publishedMercurePayloads,
            static fn (array $payload): bool => $payload['syncType'] === $syncType && $payload['status'] === 'notification',
        ));
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
    private function makeJobData(int $jobId, int $installerId, string $status, int $productTypeId = self::TYPE_ID_MISSING_FROM_SDE): array
    {
        return [
            'job_id' => $jobId,
            'installer_id' => $installerId,
            'activity_id' => 1,
            'blueprint_type_id' => 999,
            'product_type_id' => $productTypeId,
            'runs' => 5,
            'cost' => 100_000.0,
            'status' => $status,
            'facility_id' => 60003760,
            'start_date' => (new \DateTimeImmutable('-1 day'))->format('c'),
            'end_date' => (new \DateTimeImmutable('+1 day'))->format('c'),
        ];
    }
}
