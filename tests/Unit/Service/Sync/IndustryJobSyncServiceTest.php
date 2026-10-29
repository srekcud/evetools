<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Sync;

use App\Dto\CharacterInfoDto;
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
use App\Service\ESI\CharacterService;
use App\Service\ESI\EsiClient;
use App\Service\ESI\PaginatedEsiResponse;
use App\Service\Mercure\MercurePublisherService;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Sync\IndustryJobSyncService;
use App\Tests\Unit\Service\Industry\ResolvesTypeNamesFromSde;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IndustryJobSyncService::class)]
#[AllowMockObjectsWithoutExpectations]
class IndustryJobSyncServiceTest extends TestCase
{
    use ResolvesTypeNamesFromSde;

    private const int TYPE_ID_MISSING_FROM_SDE = 1000;
    private const int TRITANIUM_TYPE_ID = 34;
    private const string TRITANIUM_NAME = 'Tritanium';
    private const string CHARACTER_JOBS_SCOPE = 'esi-industry.read_character_jobs.v1';
    private const string CORPORATION_JOBS_SCOPE = 'esi-industry.read_corporation_jobs.v1';
    private const int CHARACTER_A_ID = 12345;
    private const int ALT_B_ID = 67890;
    private const int FIRST_CORPMATE_ID = 55501;
    private const int SECOND_CORPMATE_ID = 55502;
    private const int THIRD_CORPMATE_ID = 55503;
    private const int RIFTER_BLUEPRINT_TYPE_ID = 1001;
    private const string RIFTER_BLUEPRINT_NAME = 'Rifter Blueprint';
    private const int RIFTER_TYPE_ID = 2001;
    private const string RIFTER_NAME = 'Rifter';
    private const int MANUFACTURING_JOB_ID = 500;
    private const int ME_RESEARCH_JOB_ID = 501;
    private const int ME_RESEARCH_ACTIVITY_ID = 4;
    private const int CORPORATION_ID = 98000001;
    private const int OTHER_CORPORATION_ID = 98000002;
    private const string CORPORATION_JOBS_ENDPOINT = '/corporations/98000001/industry/jobs/?include_completed=true';
    private const string OTHER_CORPORATION_JOBS_ENDPOINT = '/corporations/98000002/industry/jobs/?include_completed=true';
    private const int CHARACTER_C_ID = 55501;
    private const int CHARACTER_D_WITHOUT_ROLES_ID = 55600;
    private const int CHARACTER_E_OF_OTHER_CORPORATION_ID = 55700;
    private const int CORPORATION_JOB_OF_A_ID = 700;
    private const int CORPORATION_JOB_OF_C_ID = 701;
    private const int CORPORATION_JOB_OF_D_ID = 702;
    private const int CORPORATION_JOB_OF_OUTSIDER_ID = 703;
    private const int CORPORATION_JOB_OF_E_ID = 710;
    private const int PERSONAL_JOB_OF_D_ID = 100;
    private const int ESI_EXPIRES_AFTER_SECONDS = 300;
    private const string OLD_CORPORATION_NAME = 'Old Corp';
    private const string NEW_CORPORATION_NAME = 'New Corp';
    private const int NEW_ALLIANCE_ID = 99000001;
    private const string NEW_ALLIANCE_NAME = 'New Alliance';
    private const string PERSONAL_JOBS_ENDPOINT_OF_A = '/characters/12345/industry/jobs/?include_completed=true';
    private const int STALE_PERSONAL_JOB_ID = 100;
    private const int CORPORATION_JOB_IN_NEW_CORPORATION_ID = 710;
    private const int CORPORATION_JOB_IN_OLD_CORPORATION_ID = 700;

    private EsiClient&MockObject $esiClient;
    private CachedIndustryJobRepository&MockObject $jobRepository;
    private InvTypeRepository&Stub $invTypeRepository;
    private EntityManagerInterface&MockObject $em;
    private IndustryStepJobMatchRepository&Stub $jobMatchRepository;
    private NotificationDispatcher&MockObject $notificationDispatcher;
    private IndustryJobSyncService $service;
    private array $publishedMercurePayloads = [];
    private array $publishedMercureUpdates = [];
    private BufferingLogger $logger;
    private array $persistedJobs = [];
    private int $flushCount = 0;
    private array $jobMatches = [];
    private array $reservedCompletionNotificationJobIds = [];
    private array $completionNotificationEvents = [];
    private array $dispatchedNotifications = [];
    private array $notificationRecipients = [];
    private ArrayAdapter $esiCache;
    private MockClock $esiCacheClock;
    private array $corporationJobsReads = [];
    private array $corporationJobsReadOutcomes = [];
    private array $forbiddenCorporationJobsEndpoints = [];
    private array $personalJobsReads = [];
    private array $characterInfoReads = [];
    private array $characterInfoReadOutcomes = [];
    private array $esiReadsInOrder = [];

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
        $this->esiClient = $this->createMock(EsiClient::class);
        $this->jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $this->invTypeRepository = $this->sdeTypeRepositoryKnowing([
            self::NITROGEN_FUEL_BLOCK_TYPE_ID => self::NITROGEN_FUEL_BLOCK_NAME,
            self::TRITANIUM_TYPE_ID => self::TRITANIUM_NAME,
            self::RIFTER_BLUEPRINT_TYPE_ID => self::RIFTER_BLUEPRINT_NAME,
            self::RIFTER_TYPE_ID => self::RIFTER_NAME,
        ]);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->jobMatchRepository = $this->createStub(IndustryStepJobMatchRepository::class);
        $this->notificationDispatcher = $this->createMock(NotificationDispatcher::class);
        $this->jobRepository->method('claimCompletionNotification')->willReturnCallback(
            function (int $jobId, \DateTimeImmutable $claimedAt): bool {
                $this->completionNotificationEvents[] = ['claim', $jobId];
                if (in_array($jobId, $this->reservedCompletionNotificationJobIds, true)) {
                    return false;
                }
                $this->reservedCompletionNotificationJobIds[] = $jobId;

                return true;
            },
        );
        $this->jobRepository->method('releaseCompletionNotification')->willReturnCallback(
            function (int $jobId): void {
                $this->completionNotificationEvents[] = ['release', $jobId];
                $this->reservedCompletionNotificationJobIds = array_values(array_diff($this->reservedCompletionNotificationJobIds, [$jobId]));
            },
        );

        $mercureHub = $this->createStub(HubInterface::class);
        $mercureHub->method('publish')->willReturnCallback(function (Update $update): string {
            $payload = json_decode($update->getData(), true, flags: JSON_THROW_ON_ERROR);
            $this->publishedMercurePayloads[] = $payload;
            $this->publishedMercureUpdates[] = [$update->getTopics()[0], $payload];

            return 'published';
        });

        $mercurePublisher = new MercurePublisherService($mercureHub, new NullLogger());

        $this->esiCacheClock = new MockClock();
        $this->esiCache = new ArrayAdapter(clock: $this->esiCacheClock);
        $this->esiClient->method('getPaginatedWithExpiry')->willReturnCallback(
            function (string $endpoint): PaginatedEsiResponse {
                $this->corporationJobsReads[] = $endpoint;
                $this->esiReadsInOrder[] = $endpoint;
                if (in_array($endpoint, $this->forbiddenCorporationJobsEndpoints, true)) {
                    throw EsiApiException::forbidden(endpoint: $endpoint);
                }
                $outcome = count($this->corporationJobsReadOutcomes) > 1
                    ? array_shift($this->corporationJobsReadOutcomes)
                    : ($this->corporationJobsReadOutcomes[0] ?? $this->corporationJobsResponse([]));
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            },
        );

        $this->service = new IndustryJobSyncService(
            $this->esiClient,
            $this->jobRepository,
            $this->invTypeRepository,
            $this->em,
            $this->logger,
            $mercurePublisher,
            $this->jobMatchRepository,
            $this->notificationDispatcher,
            $this->esiCache,
            $this->characterServiceReadingRecordedAffiliations(),
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

    #[Group('issue-209')]
    public function testPausedJobPastItsEndDateMissingFromEsiStaysPausedWithoutCompletionDate(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $pausedJob = $this->makeCachedJob(300, $character, 'paused', '2026-10-01 08:30:00');
        $this->stubActiveJobsByCharacter([$pausedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame('paused', $pausedJob->getStatus());
        $this->assertNull($pausedJob->getCompletedDate());
    }

    #[Group('issue-209')]
    public function testActiveJobPastItsEndDateIsStillMarkedDeliveredWhileAPausedJobOfTheSameSyncStaysPaused(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $pausedJob = $this->makeCachedJob(302, $character, 'paused', '2026-10-01 08:30:00');
        $activeJob = $this->makeCachedJob(303, $character, 'active', '2026-10-05 14:15:00');
        $this->stubActiveJobsByCharacter([$pausedJob, $activeJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame('delivered', $activeJob->getStatus());
        $this->assertEquals(new \DateTimeImmutable('2026-10-05 14:15:00'), $activeJob->getCompletedDate());
        $this->assertSame('paused', $pausedJob->getStatus());
        $this->assertNull($pausedJob->getCompletedDate());
    }

    #[Group('issue-209')]
    public function testPausedJobWithFutureEndDateMissingFromEsiStaysPaused(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $pausedJob = $this->makeCachedJob(301, $character, 'paused', '+3 days');
        $this->stubActiveJobsByCharacter([$pausedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame('paused', $pausedJob->getStatus());
        $this->assertNull($pausedJob->getCompletedDate());
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
        $this->givenCorporationJobs([
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

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
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
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([(new IndustryStepJobMatch())->setEsiJobId(7002)->setStep($step)]);

        $this->service->syncCharacterJobs($character);

        $projectNotifications = $this->mercureNotificationsOf('industry-project');
        $this->assertCount(1, $projectNotifications);
        $this->assertSame(
            'Project "Batch": type 1000 (name missing from the SDE) completed',
            $projectNotifications[0]['message'],
        );
    }

    #[Group('issue-213')]
    public function testCorporationJobsReadOnceForMainAndAltSyncedByTwoMessagesAndAltReceivesThem(): void
    {
        [$characterA, $altB] = $this->createCharactersOfSameUser(self::CHARACTER_A_ID, self::ALT_B_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs($this->corporationJobsOfSpec213());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($altB);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame(
            [
                [$this->userTopicOf($characterA), '1 active jobs, 0 ready to deliver', ['total' => 1, 'active' => 1, 'ready' => 0]],
                [$this->userTopicOf($altB), '1 active jobs, 0 ready to deliver', ['total' => 1, 'active' => 1, 'ready' => 0]],
            ],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-213')]
    public function testSecondSyncOfSameCharacterServedByCacheStillReceivesItsCorporationJobs(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs($this->corporationJobsOfSpec213());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterA);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame(
            [
                [$this->userTopicOf($characterA), '1 active jobs, 0 ready to deliver', ['total' => 1, 'active' => 1, 'ready' => 0]],
                [$this->userTopicOf($characterA), '1 active jobs, 0 ready to deliver', ['total' => 1, 'active' => 1, 'ready' => 0]],
            ],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-213')]
    public function testCorporationJobsReadOnceForCharactersOfTwoUsersOfSameCorporation(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs($this->corporationJobsOfSpec213());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterC);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
    }

    #[Group('issue-213')]
    public function testCharacterOfAnotherUserServedByCacheRecordsCountsAndNotifiesOnlyItsOwnCorporationJob(): void
    {
        [$characterA] = $this->createCharactersOfSameUser(self::CHARACTER_A_ID, self::ALT_B_ID);
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        $characterD = $this->createCharacterWithUser(self::CHARACTER_D_WITHOUT_ROLES_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs($this->corporationJobsOfSpec213());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $corporationJobOfA = $this->makeCachedJob(self::CORPORATION_JOB_OF_A_ID, $characterA, 'active', '+1 day');
        $corporationJobOfD = $this->makeCachedJob(self::CORPORATION_JOB_OF_D_ID, $characterD, 'active', '+1 day');
        $this->stubCachedJobs([$corporationJobOfA, $corporationJobOfD]);
        $this->stubActiveJobsByCharacter([$corporationJobOfA, $corporationJobOfD]);
        $this->recordPersistedJobs();
        $this->recordDispatchedNotifications();
        $this->service->syncCharacterJobs($characterA);
        $corporationJobOfABeforeSyncOfC = $this->cachedJobSnapshot($corporationJobOfA);
        $corporationJobOfDBeforeSyncOfC = $this->cachedJobSnapshot($corporationJobOfD);
        $this->forgetRecordedSideEffects();

        $this->service->syncCharacterJobs($characterC);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame(
            [[self::CORPORATION_JOB_OF_C_ID, 4, 'ready', self::TRITANIUM_TYPE_ID, 2_340_000.0]],
            array_map(
                static fn (CachedIndustryJob $job): array => [$job->getJobId(), $job->getRuns(), $job->getStatus(), $job->getProductTypeId(), $job->getCost()],
                $this->persistedJobs,
            ),
        );
        $this->assertSame($characterC, $this->persistedJobs[0]->getCharacter());
        $this->assertSame($corporationJobOfABeforeSyncOfC, $this->cachedJobSnapshot($corporationJobOfA));
        $this->assertSame($corporationJobOfDBeforeSyncOfC, $this->cachedJobSnapshot($corporationJobOfD));
        $this->assertSame(
            [[$this->userTopicOf($characterC), '0 active jobs, 1 ready to deliver', ['total' => 1, 'active' => 0, 'ready' => 1]]],
            $this->industryJobsSyncCompletedEvents(),
        );
        $userIdOfC = $characterC->getUser()->getId()->toRfc4122();
        $this->assertSame(
            ["/user/{$userIdOfC}/sync/industry-jobs", "/user/{$userIdOfC}/sync/industry-job-completed"],
            array_values(array_unique(array_column($this->publishedMercureUpdates, 0))),
        );
        $this->assertSame([self::CORPORATION_JOB_OF_C_ID], $this->jobIdsOfMercureJobCompletedNotifications());
        $this->assertSame([$characterC->getUser()], $this->notificationRecipients);
        $this->assertSame(
            [[self::CORPORATION_JOB_OF_C_ID, 'Tritanium Manufacturing (4x) ready to deliver']],
            array_map(
                static fn (array $notification): array => [$notification[4]['jobId'], $notification[3]],
                $this->dispatchedNotifications,
            ),
        );
    }

    #[Group('issue-213')]
    public function testCorporationJobsReadAgainOnceEsiExpiresHasPassed(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs($this->corporationJobsOfSpec213());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->esiCacheClock->sleep(self::ESI_EXPIRES_AFTER_SECONDS + 1);
        $this->service->syncCharacterJobs($characterC);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
    }

    #[Group('issue-213')]
    public function testCorporationJobsStillServedByCacheJustBeforeEsiExpires(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs($this->corporationJobsOfSpec213());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->esiCacheClock->sleep(self::ESI_EXPIRES_AFTER_SECONDS - 1);
        $this->service->syncCharacterJobs($characterC);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
    }

    #[Group('issue-213')]
    public function testCorporationJobsWhoseEsiExpiresIsAlreadyPastAtReceptionAreReadAgainByNextCharacter(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobsReadOutcomes(
            new PaginatedEsiResponse($this->corporationJobsOfSpec213(), new \DateTimeImmutable('-1 second')),
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterC);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
    }

    #[Group('issue-213')]
    public function testCorporationJobsWithoutEsiExpiresAreNotCachedAndReadAgainByNextCharacter(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobsReadOutcomes(new PaginatedEsiResponse($this->corporationJobsOfSpec213(), null));
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterC);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame([], $this->storedCacheEntries());
    }

    #[Group('issue-213')]
    public function testForbiddenCorporationJobsReadOnEmptyCacheCachesNothingAndNextCharacterReadsEsi(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterD = $this->createCharacterWithUser(self::CHARACTER_D_WITHOUT_ROLES_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobsReadOutcomes(
            EsiApiException::forbidden(),
            $this->corporationJobsResponse($this->corporationJobsOfSpec213()),
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $personalStaleJobOfD = $this->makeCachedJob(self::PERSONAL_JOB_OF_D_ID, $characterD, 'active', '-3 hours');
        $this->stubActiveJobsByCharacter([$personalStaleJobOfD]);

        $this->service->syncCharacterJobs($characterD);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame([], $this->storedCacheEntries());
        $this->assertSame('active', $personalStaleJobOfD->getStatus());
        $this->assertNull($personalStaleJobOfD->getCompletedDate());
        $this->assertCount(1, $this->loggedWarnings());

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
    }

    #[Group('issue-213')]
    public function testCharacterWithoutCorporationRolesServedByWarmCacheMakesNoCallLogsNoWarningAndMarksStalePersonalJobDelivered(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterD = $this->createCharacterWithUser(self::CHARACTER_D_WITHOUT_ROLES_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobsReadOutcomes(
            $this->corporationJobsResponse($this->corporationJobsOfSpec213()),
            EsiApiException::forbidden(),
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $corporationJobOfD = $this->makeCachedJob(self::CORPORATION_JOB_OF_D_ID, $characterD, 'active', '+1 day');
        $personalStaleJobOfD = $this->makeCachedJob(self::PERSONAL_JOB_OF_D_ID, $characterD, 'active', '-3 hours');
        $this->stubCachedJobs([$corporationJobOfD]);
        $this->stubActiveJobsByCharacter([$corporationJobOfD, $personalStaleJobOfD]);
        $this->service->syncCharacterJobs($characterA);
        $this->loggedWarnings();

        $this->service->syncCharacterJobs($characterD);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame([], $this->loggedWarnings());
        $this->assertSame(['active', 6, 3_100_000.5], [$corporationJobOfD->getStatus(), $corporationJobOfD->getRuns(), $corporationJobOfD->getCost()]);
        $this->assertSame('delivered', $personalStaleJobOfD->getStatus());
        $this->assertSame(
            $personalStaleJobOfD->getEndDate()->format('c'),
            $personalStaleJobOfD->getCompletedDate()?->format('c'),
        );
    }

    #[Group('issue-213')]
    public function testCharacterWithoutCorporationJobsScopeNeitherReadsCorporationJobsNorReceivesThemFromWarmCache(): void
    {
        [$characterA, $characterWithoutCorporationJobsScope] = $this->createCharactersOfSameUserWithScopes([
            self::CHARACTER_A_ID => [self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE],
            self::ALT_B_ID => [self::CHARACTER_JOBS_SCOPE],
        ]);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs($this->corporationJobsOfSpec213());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);
        $this->service->syncCharacterJobs($characterA);
        $this->forgetRecordedSideEffects();

        $this->service->syncCharacterJobs($characterWithoutCorporationJobsScope);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame(
            [[$this->userTopicOf($characterWithoutCorporationJobsScope), '0 active jobs, 0 ready to deliver', ['total' => 0, 'active' => 0, 'ready' => 0]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-213')]
    public function testCharacterOfAnotherCorporationReadsItsOwnCorporationJobsInsteadOfTheCachedOnes(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterE = $this->createCharacterWithUser(self::CHARACTER_E_OF_OTHER_CORPORATION_ID, self::OTHER_CORPORATION_ID);
        $this->esiClient->method('get')->willReturn([]);
        $corporationJobOfE = $this->makeJobData(self::CORPORATION_JOB_OF_E_ID, self::CHARACTER_E_OF_OTHER_CORPORATION_ID, 'ready', self::RIFTER_BLUEPRINT_TYPE_ID);
        $corporationJobOfE['runs'] = 3;
        $this->givenCorporationJobsReadOutcomes(
            $this->corporationJobsResponse($this->corporationJobsOfSpec213()),
            $this->corporationJobsResponse([$corporationJobOfE]),
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);
        $this->recordPersistedJobs();
        $this->service->syncCharacterJobs($characterA);
        $this->forgetRecordedSideEffects();

        $this->service->syncCharacterJobs($characterE);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::OTHER_CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame([[self::CORPORATION_JOB_OF_E_ID, 3, 'ready']], array_map(
            static fn (CachedIndustryJob $job): array => [$job->getJobId(), $job->getRuns(), $job->getStatus()],
            $this->persistedJobs,
        ));
        $this->assertSame(
            [[$this->userTopicOf($characterE), '0 active jobs, 1 ready to deliver', ['total' => 1, 'active' => 0, 'ready' => 1]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-213')]
    public function testCorporationJobsReadFailureOnEmptyCachePropagatesCachesNothingAndNextSyncReadsAgain(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $this->esiClient->method('get')->willReturn([]);
        $serviceUnavailable = EsiApiException::fromResponse(503, 'Service Unavailable');
        $this->givenCorporationJobsReadOutcomes(
            $serviceUnavailable,
            $this->corporationJobsResponse($this->corporationJobsOfSpec213()),
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);

        $this->assertSame($serviceUnavailable, $this->syncCatchingFailure($characterA));
        $this->assertSame([], $this->storedCacheEntries());

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
    }

    #[Group('issue-213')]
    public function testProgressPublishedToCharacterWithoutCorporationRolesServedByWarmCacheCountsOnlyJobsOfItsUser(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterD = $this->createCharacterWithUser(self::CHARACTER_D_WITHOUT_ROLES_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobsReadOutcomes(
            $this->corporationJobsResponse($this->corporationJobsOfSpec213()),
            EsiApiException::forbidden(),
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);
        $this->service->syncCharacterJobs($characterA);
        $this->forgetRecordedSideEffects();

        $this->service->syncCharacterJobs($characterD);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame(
            [
                [$this->userTopicOf($characterD), 30, 'Personal jobs fetched...'],
                [$this->userTopicOf($characterD), 60, 'Processing 1 jobs...'],
            ],
            $this->industryJobsSyncProgressEvents(),
        );
    }

    #[Group('issue-213')]
    public function testPersonalJobStatusWinsOverCorporationJobServedByCacheForTheSameJob(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        $personalJobOfA = $this->corporationJobOfSpec213(self::CORPORATION_JOB_OF_A_ID, self::CHARACTER_A_ID, 'ready', self::RIFTER_TYPE_ID, 10, 1_250_000.75);
        $this->esiClient->method('get')->willReturnCallback(
            static fn (string $endpoint): array => str_starts_with($endpoint, '/characters/' . self::CHARACTER_A_ID . '/')
                ? [$personalJobOfA]
                : [],
        );
        $this->givenCorporationJobs($this->corporationJobsOfSpec213());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $cachedJobOfA = $this->makeCachedJob(self::CORPORATION_JOB_OF_A_ID, $characterA, 'active', '+1 day');
        $this->stubCachedJobs([$cachedJobOfA]);
        $this->stubActiveJobsByCharacter([$cachedJobOfA]);
        $this->service->syncCharacterJobs($characterC);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame('ready', $cachedJobOfA->getStatus());
    }

    #[Group('issue-213')]
    public function testCorporationJobPastItsEndDateServedByCacheIsUpdatedNotMarkedDelivered(): void
    {
        $characterA = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        $this->esiClient->method('get')->willReturn([]);
        $corporationJobsWithJobOfAPastItsEndDate = $this->corporationJobsOfSpec213();
        $corporationJobsWithJobOfAPastItsEndDate[0]['end_date'] = '2026-10-20T08:00:00Z';
        $this->givenCorporationJobs($corporationJobsWithJobOfAPastItsEndDate);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $corporationJobOfA = $this->makeCachedJob(self::CORPORATION_JOB_OF_A_ID, $characterA, 'active', '2026-10-20T08:00:00Z');
        $this->stubCachedJobs([$corporationJobOfA]);
        $this->stubActiveJobsByCharacter([$corporationJobOfA]);
        $this->service->syncCharacterJobs($characterC);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame('active', $corporationJobOfA->getStatus());
        $this->assertNull($corporationJobOfA->getCompletedDate());
        $this->assertSame(10, $corporationJobOfA->getRuns());
        $this->assertSame(1_250_000.75, $corporationJobOfA->getCost());
    }

    public function testForbiddenCorporationJobsReadMarksNoJobAsDelivered(): void
    {
        [$character, $corporationJob, $personalStaleJob] = $this->givenCharacterWithReadyCorporationJobAndStalePersonalJob();
        $this->givenCorporationJobsReadOutcomes(EsiApiException::forbidden());

        $this->service->syncCharacterJobs($character);

        $this->assertSame('ready', $corporationJob->getStatus());
        $this->assertNull($corporationJob->getCompletedDate());
        $this->assertSame('active', $personalStaleJob->getStatus());
        $this->assertNull($personalStaleJob->getCompletedDate());
    }

    public function testForbiddenCorporationJobsReadStillRecordsPersonalJobsFromEsi(): void
    {
        [$character] = $this->givenCharacterWithReadyCorporationJobAndStalePersonalJob();
        $this->givenCorporationJobsReadOutcomes(EsiApiException::forbidden());

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
        $this->givenCorporationJobsReadOutcomes(EsiApiException::forbidden());

        $this->service->syncCharacterJobs($character);

        $this->assertCount(1, $this->loggedWarnings());
    }

    #[DataProvider('corporationJobsReadFailuresOtherThanForbidden')]
    public function testCorporationJobsReadFailureOtherThanForbiddenPropagatesWithoutWriting(\Throwable $corporationJobsReadFailure): void
    {
        [$character, $corporationJob, $personalStaleJob] = $this->givenCharacterWithReadyCorporationJobAndStalePersonalJob();
        $this->givenCorporationJobsReadOutcomes($corporationJobsReadFailure);

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
        $this->givenCorporationJobs([
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
        $this->givenCorporationJobs([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJobOfB = $this->makeCachedJob(100, $characterB, 'active', '-3 hours');
        $this->stubActiveJobsByCharacter([$personalStaleJobOfB]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame('delivered', $personalStaleJobOfB->getStatus());
    }

    public function testStalePersonalJobMarkedDeliveredForCharacterWithoutCorporationJobsScope(): void
    {
        $character = $this->createCharacterWithoutCorporationJobsScope(12345);
        $this->esiClient->method('get')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJob = $this->makeCachedJob(100, $character, 'active', '-3 hours');
        $this->stubActiveJobsByCharacter([$personalStaleJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([], $this->corporationJobsReads);
        $this->assertSame('delivered', $personalStaleJob->getStatus());
    }

    public function testReadyPersonalJobPastItsEndDateReadFromEsiStaysReadyWithoutCompletionDate(): void
    {
        $character = $this->createCharacterWithoutCorporationJobsScope(12345);
        $this->esiClient->method('get')->willReturn([
            array_merge($this->makeJobData(500, 12345, 'ready'), ['end_date' => '2026-10-18T14:20:00Z']),
        ]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $readyPersonalJob = $this->makeCachedJob(500, $character, 'ready', '2026-10-18T14:20:00Z');
        $this->stubCachedJobs([$readyPersonalJob]);
        $this->stubActiveJobsByCharacter([$readyPersonalJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([], $this->corporationJobsReads);
        $this->assertSame('ready', $readyPersonalJob->getStatus());
        $this->assertNull($readyPersonalJob->getCompletedDate());
    }

    public function testStalePersonalJobStaysActiveForCharacterWithoutAnyJobsScope(): void
    {
        [$character] = $this->createCharactersOfSameUserWithScopes([12345 => []]);
        $this->esiClient->expects($this->never())->method('get');
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJob = $this->makeCachedJob(100, $character, 'active', '-1 day');
        $this->stubActiveJobsByCharacter([$personalStaleJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([], $this->corporationJobsReads);
        $this->assertSame('active', $personalStaleJob->getStatus());
        $this->assertNull($personalStaleJob->getCompletedDate());
    }

    public function testStalePersonalJobStaysActiveWhileCorporationJobIsRecordedForCharacterWithCorporationJobsScopeOnly(): void
    {
        [$character] = $this->createCharactersOfSameUserWithScopes([12345 => [self::CORPORATION_JOBS_SCOPE]]);
        $this->esiClient->expects($this->never())->method('get');
        $corporationJobData = $this->makeJobData(900, 12345, 'ready');
        $corporationJobData['runs'] = 3;
        $this->givenCorporationJobs([$corporationJobData]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->jobRepository->method('findByJobId')->willReturn(null);
        $personalStaleJob = $this->makeCachedJob(100, $character, 'active', '-1 day');
        $this->stubActiveJobsByCharacter([$personalStaleJob]);
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedJobs[] = $entity;
        });

        $this->service->syncCharacterJobs($character);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame('active', $personalStaleJob->getStatus());
        $this->assertNull($personalStaleJob->getCompletedDate());
        $this->assertSame([[900, 3, 'ready']], array_map(
            static fn (CachedIndustryJob $job): array => [$job->getJobId(), $job->getRuns(), $job->getStatus()],
            $this->persistedJobs,
        ));
    }

    #[Group('issue-213')]
    public function testStalePersonalJobStaysActiveForCharacterWithCorporationJobsScopeOnlyWhenCorporationJobsServedByCache(): void
    {
        [$characterB, $characterA] = $this->createCharactersOfSameUserWithScopes([
            67890 => [self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE],
            12345 => [self::CORPORATION_JOBS_SCOPE],
        ]);
        $this->esiClient->expects($this->once())
            ->method('get')
            ->with('/characters/67890/industry/jobs/?include_completed=true')
            ->willReturn([]);
        $this->givenCorporationJobs([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJobOfA = $this->makeCachedJob(100, $characterA, 'active', '-1 day');
        $this->stubActiveJobsByCharacter([$personalStaleJobOfA]);

        $this->service->syncCharacterJobs($characterB);
        $this->service->syncCharacterJobs($characterA);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame('active', $personalStaleJobOfA->getStatus());
        $this->assertNull($personalStaleJobOfA->getCompletedDate());
    }

    public function testCorporationJobsReadAgainBySecondCharacterAfterForbiddenRead(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobsReadOutcomes(EsiApiException::forbidden());
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
    }

    public function testCharacterWithCorporationRolesReadsCorporationJobsAfterForbiddenReadOfAnother(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobsReadOutcomes(EsiApiException::forbidden(), $this->corporationJobsResponse([]));
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $personalStaleJobOfB = $this->makeCachedJob(100, $characterB, 'active', '-3 hours');
        $this->stubActiveJobsByCharacter([$personalStaleJobOfB]);

        $this->service->syncCharacterJobs($characterA);
        $this->service->syncCharacterJobs($characterB);

        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame('delivered', $personalStaleJobOfB->getStatus());
    }

    #[Group('issue-208')]
    public function testDeliveredJobRefreshesJobMatchOfManualStep(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $cachedJob = $this->givenMatchedCachedJob($character);
        $jobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('manual'));
        $this->esiClient->method('get')->willReturn([$this->makeEsiJobOfMatch(100, 12345, 'delivered', '2026-10-20T12:00:00Z')]);
        $this->stubCachedJobs([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(['delivered', '2026-10-20T12:00:00+00:00', 1_500_000.50, 10], $this->jobStateOf($jobMatch));
    }

    #[Group('issue-208')]
    public function testReadyJobRefreshesJobMatchOfPurchasedStep(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $cachedJob = $this->givenMatchedCachedJob($character);
        $jobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('auto')->setPurchased(true));
        $this->esiClient->method('get')->willReturn([$this->makeEsiJobOfMatch(100, 12345, 'ready', '2026-10-20T12:00:00Z')]);
        $this->stubCachedJobs([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(['ready', '2026-10-20T12:00:00+00:00', 1_500_000.50, 10], $this->jobStateOf($jobMatch));
    }

    #[Group('issue-208')]
    public function testDeliveredJobRefreshesJobMatchOfAutoStepWithoutMatchingJobsAgain(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $cachedJob = $this->givenMatchedCachedJob($character);
        $jobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('auto'));
        $this->esiClient->method('get')->willReturn([$this->makeEsiJobOfMatch(100, 12345, 'delivered', '2026-10-20T12:00:00Z')]);
        $this->stubCachedJobs([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(['delivered', '2026-10-20T12:00:00+00:00', 1_500_000.50, 10], $this->jobStateOf($jobMatch));
    }

    #[Group('issue-208')]
    public function testNewEndDateFromEsiRefreshesCachedJobAndJobMatch(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $cachedJob = $this->givenMatchedCachedJob($character);
        $jobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('manual'));
        $this->esiClient->method('get')->willReturn([$this->makeEsiJobOfMatch(100, 12345, 'active', '2026-10-21T08:30:00Z')]);
        $this->stubCachedJobs([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame('2026-10-21T08:30:00+00:00', $cachedJob->getEndDate()->format('c'));
        $this->assertSame(['active', '2026-10-21T08:30:00+00:00', 1_500_000.50, 10], $this->jobStateOf($jobMatch));
    }

    #[Group('issue-208')]
    public function testStaleJobMarkedDeliveredBySyncRefreshesJobMatch(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $cachedJob = $this->givenMatchedCachedJob($character);
        $jobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('manual'));
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs([]);
        $this->stubActiveJobsByCharacter([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(['delivered', '2026-10-20T12:00:00+00:00', 1_500_000.50, 10], $this->jobStateOf($jobMatch));
    }

    #[Group('issue-208')]
    public function testJobMatchOfCorpmateJobReadByCorporationEndpointIsNotRefreshed(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $corpmateCachedJob = $this->makeCachedJob(200, $this->createCharacterWithUser(99999), 'delivered', '2026-10-19T16:45:00Z');
        $corpmateCachedJob->setCost(2_750_000.75)->setRuns(4);
        $jobMatch = $this->givenJobMatchOnStep(200, $this->makeStep('manual'));
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs([$this->makeEsiJobOfMatch(200, 99999, 'delivered', '2026-10-19T16:45:00Z')]);
        $this->stubCachedJobs([$corpmateCachedJob]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(['active', '2026-10-20T12:00:00+00:00', 1_500_000.50, 10], $this->jobStateOf($jobMatch));
    }

    #[Group('issue-208')]
    public function testJobMatchOfUnreadPersonalJobIsNotRefreshedWithoutPersonalJobsScope(): void
    {
        [$character] = $this->createCharactersOfSameUserWithScopes([12345 => [self::CORPORATION_JOBS_SCOPE]]);
        $cachedJob = $this->givenMatchedCachedJob($character)->setStatus('ready');
        $jobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('manual'));
        $this->esiClient->expects($this->never())->method('get');
        $this->givenCorporationJobs([]);
        $this->stubCachedJobs([$cachedJob]);
        $this->stubActiveJobsByCharacter([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(['active', '2026-10-20T12:00:00+00:00', 1_500_000.50, 10], $this->jobStateOf($jobMatch));
    }

    #[Group('issue-208')]
    public function testJobMatchTakesCostAndRunsOfCachedJobWhenTheyDiverge(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $cachedJob = $this->givenMatchedCachedJob($character);
        $jobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('manual'))->setCost(900_000.0)->setRuns(5);
        $this->esiClient->method('get')->willReturn([$this->makeEsiJobOfMatch(100, 12345, 'active', '2026-10-20T12:00:00Z')]);
        $this->stubCachedJobs([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(1_500_000.50, $jobMatch->getCost());
        $this->assertSame(10, $jobMatch->getRuns());
    }

    #[Group('issue-208')]
    public function testRefreshKeepsCharacterFacilityPlannedStructureAndMatchDateOfJobMatch(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $cachedJob = $this->givenMatchedCachedJob($character);
        $jobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('manual'));
        $this->esiClient->method('get')->willReturn([$this->makeEsiJobOfMatch(100, 12345, 'delivered', '2026-10-20T12:00:00Z')]);
        $this->stubCachedJobs([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            ['Main Industrialist', 1_035_466_617_946, 'Sotiyo Kemerk', 'Azbel Planned', 1.0, '2026-10-15T09:00:00+00:00'],
            [
                $jobMatch->getCharacterName(),
                $jobMatch->getFacilityId(),
                $jobMatch->getFacilityName(),
                $jobMatch->getPlannedStructureName(),
                $jobMatch->getPlannedMaterialBonus(),
                $jobMatch->getMatchedAt()->format('c'),
            ],
        );
    }

    #[Group('issue-208')]
    public function testJobMatchedOnTwoStepsIsRefreshedOnBoth(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $cachedJob = $this->givenMatchedCachedJob($character);
        $firstJobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('manual'));
        $secondJobMatch = $this->givenJobMatchOnStep(100, $this->makeStep('auto'));
        $this->esiClient->method('get')->willReturn([$this->makeEsiJobOfMatch(100, 12345, 'delivered', '2026-10-20T12:00:00Z')]);
        $this->stubCachedJobs([$cachedJob]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame('delivered', $firstJobMatch->getStatus());
        $this->assertSame('delivered', $secondJobMatch->getStatus());
    }

    #[Group('issue-208')]
    public function testAltJobReadByCorporationEndpointRefreshesItsJobMatch(): void
    {
        [$characterA, $characterB] = $this->createCharactersOfSameUser(12345, 67890);
        $altCachedJob = $this->makeCachedJob(400, $characterB, 'active', '2026-10-23T06:15:00Z');
        $altCachedJob->setCost(640_000.25)->setRuns(3);
        $jobMatch = $this->givenJobMatchOnStep(400, $this->makeStep('manual'));
        $this->esiClient->method('get')->willReturn([]);
        $altEsiJob = $this->makeEsiJobOfMatch(400, 67890, 'ready', '2026-10-23T06:15:00Z');
        $altEsiJob['cost'] = 640_000.25;
        $altEsiJob['runs'] = 3;
        $this->givenCorporationJobs([$altEsiJob]);
        $this->stubCachedJobs([$altCachedJob]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame(['ready', '2026-10-23T06:15:00+00:00', 640_000.25, 3], $this->jobStateOf($jobMatch));
    }

    #[Group('issue-216')]
    #[DataProvider('jobsToNotify')]
    public function testJobToNotifyIsReservedThenNotifiedOnce(?string $cachedStatus, string $esiStatus, string $expectedMessage): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturn([$this->makeTritaniumJobData(100, 12345, $esiStatus)]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $cachedJobs = $cachedStatus === null ? [] : [$this->makeCachedJob(100, $character, $cachedStatus, '-1 hour')];
        $this->stubCachedJobs($cachedJobs);
        $this->stubActiveJobsByCharacter($cachedJobs);
        $this->recordDispatchedNotifications();

        $this->service->syncCharacterJobs($character);

        $this->assertSame([['claim', 100], ['dispatch', 100]], $this->completionNotificationEvents);
        $this->assertSame([100], $this->reservedCompletionNotificationJobIds);
        $this->assertSame([$character->getUser()], $this->notificationRecipients);
        $this->assertSame([[
            Notification::CATEGORY_INDUSTRY,
            Notification::LEVEL_SUCCESS,
            'Job completed: Tritanium',
            $expectedMessage,
            [
                'jobId' => 100,
                'productTypeId' => 34,
                'productName' => 'Tritanium',
                'runs' => 10,
                'activityId' => 1,
            ],
            '/industry',
        ]], $this->dispatchedNotifications);
        $this->assertSame([100], $this->jobIdsOfMercureJobCompletedNotifications());
    }

    public static function jobsToNotify(): iterable
    {
        yield 'E1 cached active job read ready' => ['active', 'ready', 'Tritanium Manufacturing (10x) ready to deliver'];
        yield 'E2 unknown job read ready' => [null, 'ready', 'Tritanium Manufacturing (10x) ready to deliver'];
        yield 'E4 cached active job read delivered' => ['active', 'delivered', 'Tritanium Manufacturing (10x) delivered'];
        yield 'E6 cached ready job never reserved read ready' => ['ready', 'ready', 'Tritanium Manufacturing (10x) ready to deliver'];
        yield 'E7 cached paused job read ready' => ['paused', 'ready', 'Tritanium Manufacturing (10x) ready to deliver'];
    }

    #[Group('issue-216')]
    #[DataProvider('jobsWhoseNotificationIsAlreadyReserved')]
    public function testJobWhoseNotificationIsAlreadyReservedIsNotNotifiedAgain(string $cachedStatus, ?string $cachedCompletionNotifiedAt): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturn([$this->makeTritaniumJobData(100, 12345, 'ready')]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $cachedJob = $this->makeCachedJob(100, $character, $cachedStatus, '-1 hour');
        $cachedJob->setCompletionNotifiedAt($cachedCompletionNotifiedAt === null ? null : new \DateTimeImmutable($cachedCompletionNotifiedAt));
        $this->stubCachedJobs([$cachedJob]);
        $this->stubActiveJobsByCharacter([$cachedJob]);
        $this->reservedCompletionNotificationJobIds = [100];
        $this->recordDispatchedNotifications();

        $this->service->syncCharacterJobs($character);

        $this->assertSame([], $this->dispatchedNotifications);
        $this->assertSame([], $this->jobIdsOfMercureJobCompletedNotifications());
        $this->assertSame([100], $this->reservedCompletionNotificationJobIds);
        $this->assertNotContains(['release', 100], $this->completionNotificationEvents);
    }

    public static function jobsWhoseNotificationIsAlreadyReserved(): iterable
    {
        yield 'E5 cached ready job already reserved' => ['ready', '2026-10-22 09:15:00'];
        yield 'E8 cached active job reserved meanwhile by another sync' => ['active', null];
    }

    #[Group('issue-216')]
    public function testFailedSendReleasesItsReservationKeepsEarlierOnesAndPropagates(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $failedJobData = $this->makeJobData(101, 12345, 'ready', self::NITROGEN_FUEL_BLOCK_TYPE_ID);
        $failedJobData['runs'] = 4;
        $this->esiClient->method('get')->willReturn([$this->makeTritaniumJobData(100, 12345, 'ready'), $failedJobData]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $cachedJobs = [
            $this->makeCachedJob(100, $character, 'active', '-1 hour'),
            $this->makeCachedJob(101, $character, 'active', '-2 hours'),
        ];
        $this->stubCachedJobs($cachedJobs);
        $this->stubActiveJobsByCharacter($cachedJobs);
        $sendFailure = new \RuntimeException('Push endpoint unreachable');
        $this->recordDispatchedNotifications(failingJobId: 101, failure: $sendFailure);

        $this->assertSame($sendFailure, $this->syncCatchingFailure($character));

        $this->assertSame([100], $this->reservedCompletionNotificationJobIds);
        $this->assertSame(
            [['claim', 100], ['dispatch', 100], ['claim', 101], ['dispatch', 101], ['release', 101]],
            $this->completionNotificationEvents,
        );
        $this->assertSame([100], array_map(
            static fn (array $notification): int => $notification[4]['jobId'],
            $this->dispatchedNotifications,
        ));
        $this->assertSame(['Push endpoint unreachable'], $this->mercureSyncErrorMessages());
    }

    #[Group('issue-216')]
    public function testJobCreatedDeliveredIsReservedWithoutNotification(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturn([$this->makeTritaniumJobData(100, 12345, 'delivered')]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedJobs[] = $entity;
        });
        $this->recordDispatchedNotifications();
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncCharacterJobs($character);

        $this->assertSame([[100, 10, 'delivered']], array_map(
            static fn (CachedIndustryJob $job): array => [$job->getJobId(), $job->getRuns(), $job->getStatus()],
            $this->persistedJobs,
        ));
        $this->assertReservedDuringSync($this->persistedJobs[0], $syncStartedAt);
        $this->assertSame([], $this->completionNotificationEvents);
        $this->assertSame([], $this->dispatchedNotifications);
        $this->assertSame([], $this->jobIdsOfMercureJobCompletedNotifications());
    }

    #[Group('issue-216')]
    public function testStaleJobMarkedDeliveredBySyncIsReservedWithoutNotification(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturn([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $staleJob = $this->makeCachedJob(100, $character, 'active', '2026-10-21 16:40:00');
        $staleJob->setRuns(10);
        $this->stubActiveJobsByCharacter([$staleJob]);
        $this->recordDispatchedNotifications();
        $syncStartedAt = new \DateTimeImmutable();

        $this->service->syncCharacterJobs($character);

        $this->assertSame('delivered', $staleJob->getStatus());
        $this->assertReservedDuringSync($staleJob, $syncStartedAt);
        $this->assertSame([], $this->completionNotificationEvents);
        $this->assertSame([], $this->dispatchedNotifications);
        $this->assertSame([], $this->jobIdsOfMercureJobCompletedNotifications());
    }

    #[Group('issue-216')]
    public function testCorpmateJobReadReadyByCorporationEndpointIsNeitherReservedNorNotified(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs([$this->makeTritaniumJobData(200, 99999, 'ready')]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);
        $this->recordDispatchedNotifications();

        $this->service->syncCharacterJobs($character);

        $this->assertSame([], $this->completionNotificationEvents);
        $this->assertSame([], $this->dispatchedNotifications);
        $this->assertSame([], $this->jobIdsOfMercureJobCompletedNotifications());
    }

    #[Group('issue-216')]
    public function testJobReadCancelledThenRevertedIsNeitherReservedNorNotified(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $this->esiClient->method('get')->willReturnOnConsecutiveCalls(
            [$this->makeTritaniumJobData(100, 12345, 'cancelled')],
            [$this->makeTritaniumJobData(100, 12345, 'reverted')],
        );
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $cachedJob = $this->makeCachedJob(100, $character, 'active', '+4 hours');
        $this->stubCachedJobs([$cachedJob]);
        $this->stubActiveJobsByCharacter([$cachedJob]);
        $this->recordDispatchedNotifications();

        $this->service->syncCharacterJobs($character);
        $this->service->syncCharacterJobs($character);

        $this->assertSame('reverted', $cachedJob->getStatus());
        $this->assertSame([], $this->completionNotificationEvents);
        $this->assertSame([], $this->dispatchedNotifications);
        $this->assertSame([], $this->jobIdsOfMercureJobCompletedNotifications());
    }

    #[Group('issue-216')]
    public function testProjectProgressCoversOnlyNotifiedJobs(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $jobReservedByAnotherSyncData = $this->makeJobData(101, 12345, 'ready', self::NITROGEN_FUEL_BLOCK_TYPE_ID);
        $jobReservedByAnotherSyncData['runs'] = 4;
        $this->esiClient->method('get')->willReturn([$this->makeTritaniumJobData(100, 12345, 'ready'), $jobReservedByAnotherSyncData]);
        $cachedJobs = [
            $this->makeCachedJob(100, $character, 'active', '-1 hour'),
            $this->makeCachedJob(101, $character, 'active', '-3 hours'),
        ];
        $this->stubCachedJobs($cachedJobs);
        $this->stubActiveJobsByCharacter($cachedJobs);
        $this->givenJobMatchOnStep(100, $this->makeStep('manual')->setProductTypeId(self::TRITANIUM_TYPE_ID));
        $this->givenJobMatchOnStep(101, $this->makeStep('manual')->setProductTypeId(self::NITROGEN_FUEL_BLOCK_TYPE_ID));
        $this->reservedCompletionNotificationJobIds = [101];
        $this->recordDispatchedNotifications();

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            ['Project "Capital Batch": Tritanium completed'],
            array_column($this->mercureNotificationsOf('industry-project'), 'message'),
        );
    }

    #[Group('issue-216')]
    public function testJobsReadAfterARefusedReservationAreStillNotifiedAndCountedInProjectProgress(): void
    {
        $character = $this->createCharacterWithUser(12345);
        $nitrogenFuelBlockJobData = $this->makeJobData(101, 12345, 'ready', self::NITROGEN_FUEL_BLOCK_TYPE_ID);
        $nitrogenFuelBlockJobData['runs'] = 4;
        $productMissingFromSdeJobData = $this->makeJobData(102, 12345, 'delivered');
        $productMissingFromSdeJobData['runs'] = 7;
        $this->esiClient->method('get')->willReturn([
            $this->makeTritaniumJobData(100, 12345, 'ready'),
            $nitrogenFuelBlockJobData,
            $productMissingFromSdeJobData,
        ]);
        $cachedJobs = [
            $this->makeCachedJob(100, $character, 'active', '-1 hour'),
            $this->makeCachedJob(101, $character, 'active', '-2 hours'),
            $this->makeCachedJob(102, $character, 'active', '-3 hours'),
        ];
        $this->stubCachedJobs($cachedJobs);
        $this->stubActiveJobsByCharacter($cachedJobs);
        $jobMatchLookups = [];
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturnCallback(
            static function (array $esiJobIds) use (&$jobMatchLookups): array {
                $jobMatchLookups[] = $esiJobIds;

                return [];
            },
        );
        $this->reservedCompletionNotificationJobIds = [100];
        $this->recordDispatchedNotifications();

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            [
                ['Job completed: Nitrogen Fuel Block', 'Nitrogen Fuel Block Manufacturing (4x) ready to deliver'],
                ['Job completed: type 1000 (name missing from the SDE)', 'type 1000 (name missing from the SDE) Manufacturing (7x) delivered'],
            ],
            array_map(
                static fn (array $notification): array => [$notification[2], $notification[3]],
                $this->dispatchedNotifications,
            ),
        );
        $this->assertSame([100, 101, 102], $this->reservedCompletionNotificationJobIds);
        $this->assertSame([[100, 101, 102], [101, 102]], $jobMatchLookups);
    }

    #[Group('issue-216')]
    public function testJobOfUserWithoutIdIsNeitherReservedNorNotified(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(null);
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturn(true);
        $character = $this->createStub(Character::class);
        $character->method('getEveCharacterId')->willReturn(12345);
        $character->method('getEveToken')->willReturn($token);
        $character->method('getName')->willReturn('UnsavedChar');
        $character->method('getUser')->willReturn($user);
        $character->method('getCorporationId')->willReturn(98000001);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));
        $this->esiClient->method('get')->willReturn([$this->makeTritaniumJobData(100, 12345, 'ready')]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $cachedJob = $this->makeCachedJob(100, $character, 'active', '-1 hour');
        $this->stubCachedJobs([$cachedJob]);
        $this->stubActiveJobsByCharacter([$cachedJob]);
        $this->recordDispatchedNotifications();

        $this->service->syncCharacterJobs($character);

        $this->assertSame('ready', $cachedJob->getStatus());
        $this->assertSame([], $this->completionNotificationEvents);
        $this->assertSame([], $this->dispatchedNotifications);
    }

    #[Group('issue-210')]
    public function testSyncCompletedCountsOnlyJobsInstalledByCharactersOfTheUser(): void
    {
        [$characterA] = $this->createCharactersOfSameUser(self::CHARACTER_A_ID, self::ALT_B_ID);
        $this->esiClient->method('get')->willReturn([$this->makeJobData(100, self::CHARACTER_A_ID, 'active')]);
        $this->givenCorporationJobs($this->corporationJobsOfE1());
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame(
            [[$this->userTopicOf($characterA), '2 active jobs, 1 ready to deliver', ['total' => 3, 'active' => 2, 'ready' => 1]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-210')]
    public function testSyncCompletedCountsOnlyOwnCorporationJobsForCharacterWithoutPersonalJobsScope(): void
    {
        [$characterA] = $this->createCharactersOfSameUserWithScopes([
            self::CHARACTER_A_ID => [self::CORPORATION_JOBS_SCOPE],
            self::ALT_B_ID => [self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE],
        ]);
        $this->esiClient->expects($this->never())->method('get');
        $this->givenCorporationJobs($this->corporationJobsOfE1());
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame(
            [[$this->userTopicOf($characterA), '1 active jobs, 1 ready to deliver', ['total' => 2, 'active' => 1, 'ready' => 1]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-210')]
    public function testSyncCompletedCountsAltJobReadByCorporationEndpoint(): void
    {
        [$characterA] = $this->createCharactersOfSameUser(self::CHARACTER_A_ID, self::ALT_B_ID);
        $this->esiClient->method('get')->willReturn([$this->makeJobData(100, self::CHARACTER_A_ID, 'active')]);
        $this->givenCorporationJobs([
            ...$this->corporationJobsOfE1(),
            $this->makeJobData(905, self::ALT_B_ID, 'ready'),
        ]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame(
            [[$this->userTopicOf($characterA), '2 active jobs, 2 ready to deliver', ['total' => 4, 'active' => 2, 'ready' => 2]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-210')]
    public function testSyncCompletedCountsOnceAJobReadByPersonalAndCorporationEndpoints(): void
    {
        [$characterA] = $this->createCharactersOfSameUser(self::CHARACTER_A_ID, self::ALT_B_ID);
        $this->esiClient->method('get')->willReturn([
            $this->makeJobData(100, self::CHARACTER_A_ID, 'active'),
            $this->makeJobData(900, self::CHARACTER_A_ID, 'active'),
        ]);
        $this->givenCorporationJobs($this->corporationJobsOfE1());
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame(
            [[$this->userTopicOf($characterA), '2 active jobs, 1 ready to deliver', ['total' => 3, 'active' => 2, 'ready' => 1]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-210')]
    public function testSyncCompletedDoesNotCountStaleJobMarkedDeliveredBySync(): void
    {
        [$characterA] = $this->createCharactersOfSameUser(self::CHARACTER_A_ID, self::ALT_B_ID);
        $staleJob = $this->makeCachedJob(110, $characterA, 'active', '-2 days');
        $this->esiClient->method('get')->willReturn([$this->makeJobData(100, self::CHARACTER_A_ID, 'active')]);
        $this->givenCorporationJobs([]);
        $this->stubActiveJobsByCharacter([$staleJob]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame('delivered', $staleJob->getStatus());
        $this->assertSame(
            [[$this->userTopicOf($characterA), '1 active jobs, 0 ready to deliver', ['total' => 1, 'active' => 1, 'ready' => 0]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-210')]
    public function testSyncCompletedCountsPausedAndDeliveredJobsOnlyInTotal(): void
    {
        [$characterA] = $this->createCharactersOfSameUser(self::CHARACTER_A_ID, self::ALT_B_ID);
        $this->esiClient->method('get')->willReturn([$this->makeJobData(100, self::CHARACTER_A_ID, 'active')]);
        $this->givenCorporationJobs([
            ...$this->corporationJobsOfE1(),
            $this->makeJobData(906, self::CHARACTER_A_ID, 'paused'),
            $this->makeJobData(907, self::CHARACTER_A_ID, 'delivered'),
        ]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame(
            [[$this->userTopicOf($characterA), '2 active jobs, 1 ready to deliver', ['total' => 5, 'active' => 2, 'ready' => 1]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-210')]
    public function testSyncCompletedCountsNoJobWhenOnlyCorpmateJobsAreRead(): void
    {
        [$characterA] = $this->createCharactersOfSameUser(self::CHARACTER_A_ID, self::ALT_B_ID);
        $this->esiClient->method('get')->willReturn([]);
        $this->givenCorporationJobs([
            $this->makeJobData(902, self::FIRST_CORPMATE_ID, 'active'),
            $this->makeJobData(903, self::SECOND_CORPMATE_ID, 'active'),
            $this->makeJobData(904, self::THIRD_CORPMATE_ID, 'ready'),
        ]);
        $this->stubActiveJobsByCharacter([]);

        $this->service->syncCharacterJobs($characterA);

        $this->assertSame(
            [[$this->userTopicOf($characterA), '0 active jobs, 0 ready to deliver', ['total' => 0, 'active' => 0, 'ready' => 0]]],
            $this->industryJobsSyncCompletedEvents(),
        );
    }

    #[Group('issue-214')]
    public function testMeResearchJobReadWithoutCostAndProductIsCachedWithUnknownCostAndProduct(): void
    {
        $character = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $this->givenNoCachedJobAndRecordedPersists();
        $this->esiClient->method('get')->willReturn([$this->esiMeResearchJob501('active')]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            [[self::ME_RESEARCH_JOB_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 2, null, null]],
            array_map($this->cachedJobStateOf(...), $this->persistedJobs),
        );
    }

    #[Group('issue-214')]
    public function testManufacturingJobReadWithCostAndProductIsCachedWithThem(): void
    {
        $character = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $this->givenNoCachedJobAndRecordedPersists();
        $this->esiClient->method('get')->willReturn([$this->esiManufacturingJob500('active')]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            [[self::MANUFACTURING_JOB_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 10, 1_250_000.75, self::RIFTER_TYPE_ID]],
            array_map($this->cachedJobStateOf(...), $this->persistedJobs),
        );
    }

    #[Group('issue-214')]
    public function testCachedJobTakesCostAndRunsReadFromEsi(): void
    {
        $character = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $cachedJob = $this->cachedRifterJob(self::MANUFACTURING_JOB_ID, $character, 1, self::RIFTER_TYPE_ID, 8, 1_000_000.0);
        $this->stubCachedJobs([$cachedJob]);
        $this->stubActiveJobsByCharacter([$cachedJob]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->esiClient->method('get')->willReturn([$this->esiManufacturingJob500('active')]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            [self::MANUFACTURING_JOB_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 10, 1_250_000.75, self::RIFTER_TYPE_ID],
            $this->cachedJobStateOf($cachedJob),
        );
    }

    #[Group('issue-214')]
    public function testCachedJobWithFallbackCostAndProductTakesUnknownCostAndProductReadFromEsi(): void
    {
        $character = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $cachedJob = $this->cachedRifterJob(self::ME_RESEARCH_JOB_ID, $character, self::ME_RESEARCH_ACTIVITY_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 2, 0.0);
        $this->stubCachedJobs([$cachedJob]);
        $this->stubActiveJobsByCharacter([$cachedJob]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->esiClient->method('get')->willReturn([$this->esiMeResearchJob501('active')]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            [self::ME_RESEARCH_JOB_ID, self::RIFTER_BLUEPRINT_TYPE_ID, 2, null, null],
            $this->cachedJobStateOf($cachedJob),
        );
    }

    #[Group('issue-214')]
    public function testCompletionNotificationOfJobWithoutProductIsLabelledWithItsBlueprintAndKeepsProductUnknown(): void
    {
        $character = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $cachedJob = $this->cachedRifterJob(self::ME_RESEARCH_JOB_ID, $character, self::ME_RESEARCH_ACTIVITY_ID, null, 2, null);
        $this->stubCachedJobs([$cachedJob]);
        $this->stubActiveJobsByCharacter([$cachedJob]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->esiClient->method('get')->willReturn([$this->esiMeResearchJob501('ready')]);
        $this->recordDispatchedNotifications();

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            [[null, 'Job completed: Rifter Blueprint', 'Rifter Blueprint ME Research (2x) ready to deliver']],
            array_map(
                static fn (array $notification): array => [$notification[4]['productTypeId'], $notification[2], $notification[3]],
                $this->dispatchedNotifications,
            ),
        );
        $this->assertSame(
            [[null, 'Rifter Blueprint - ME Research completed!']],
            array_map(
                static fn (array $notification): array => [$notification['data']['productTypeId'], $notification['message']],
                $this->mercureNotificationsOf('industry-job-completed'),
            ),
        );
    }

    #[Group('issue-214')]
    public function testJobMatchTakesUnknownCostOfCachedJobReadWithoutCost(): void
    {
        $character = $this->createCharacterWithUser(self::CHARACTER_A_ID);
        $cachedJob = $this->cachedRifterJob(self::MANUFACTURING_JOB_ID, $character, 1, self::RIFTER_TYPE_ID, 10, 1_250_000.75);
        $jobMatch = $this->givenJobMatchOnStep(self::MANUFACTURING_JOB_ID, $this->makeStep('manual'))->setCost(1_250_000.75);
        $this->stubCachedJobs([$cachedJob]);
        $this->stubActiveJobsByCharacter([$cachedJob]);
        $esiJobWithoutCost = $this->esiManufacturingJob500('active');
        unset($esiJobWithoutCost['cost']);
        $this->esiClient->method('get')->willReturn([$esiJobWithoutCost]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([null, null], [$cachedJob->getCost(), $jobMatch->getCost()]);
    }

    #[Group('issue-211')]
    public function testCharacterMovedToAnotherCorporationTakesItsAffiliationAndReadsOnlyTheJobsOfTheNewCorporation(): void
    {
        [$character, $stalePersonalJob] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME);
        $this->givenCorporationJobs([$this->corporationJobInNewCorporation()]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME, null, null], $this->affiliationOf($character));
        $this->assertSame(
            ['affiliation of 12345', self::PERSONAL_JOBS_ENDPOINT_OF_A, self::OTHER_CORPORATION_JOBS_ENDPOINT],
            $this->esiReadsInOrder,
        );
        $this->assertSame([[self::CORPORATION_JOB_IN_NEW_CORPORATION_ID, 3, 'active', self::RIFTER_TYPE_ID, 4_200_000.25]], $this->persistedJobStates());
        $this->assertSame($character, $this->persistedJobs[0]->getCharacter());
        $this->assertSame(
            ['delivered', $stalePersonalJob->getEndDate()->format('c')],
            [$stalePersonalJob->getStatus(), $stalePersonalJob->getCompletedDate()?->format('c')],
        );
        $this->assertSame(1, $this->flushCount);
    }

    #[Group('issue-211')]
    public function testCharacterStillInItsCorporationKeepsItsAffiliationAndReadsTheJobsOfThatCorporation(): void
    {
        [$character, $stalePersonalJob] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::CORPORATION_ID, self::OLD_CORPORATION_NAME);
        $this->givenCorporationJobs([$this->corporationJobInOldCorporation()]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([self::CORPORATION_ID, self::OLD_CORPORATION_NAME, null, null], $this->affiliationOf($character));
        $this->assertSame([self::CHARACTER_A_ID], $this->characterInfoReads);
        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame([[self::CORPORATION_JOB_IN_OLD_CORPORATION_ID, 8, 'active', self::TRITANIUM_TYPE_ID, 980_000.0]], $this->persistedJobStates());
        $this->assertSame('delivered', $stalePersonalJob->getStatus());
        $this->assertSame(1, $this->flushCount);
    }

    #[Group('issue-211')]
    #[DataProvider('affiliationReadFailures')]
    public function testAffiliationReadFailurePropagatesBeforeAnyJobReadWithoutWriting(\Throwable $affiliationReadFailure): void
    {
        [$character, $stalePersonalJob] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $this->characterInfoReadOutcomes[self::CHARACTER_A_ID] = $affiliationReadFailure;
        $this->givenCorporationJobs([$this->corporationJobInNewCorporation()]);

        $this->assertSame($affiliationReadFailure, $this->syncCatchingFailure($character));
        $this->assertSame([self::CHARACTER_A_ID], $this->characterInfoReads);
        $this->assertSame([], $this->personalJobsReads);
        $this->assertSame([], $this->corporationJobsReads);
        $this->assertSame(0, $this->flushCount);
        $this->assertSame([], $this->persistedJobs);
        $this->assertSame([self::CORPORATION_ID, self::OLD_CORPORATION_NAME, null, null], $this->affiliationOf($character));
        $this->assertSame(['active', null], [$stalePersonalJob->getStatus(), $stalePersonalJob->getCompletedDate()]);
        $this->assertSame(
            [[$this->userTopicOf($character), $affiliationReadFailure->getMessage()]],
            $this->industryJobsSyncErrorEvents(),
        );
        $this->assertSame(
            [[$this->userTopicOf($character), 'started'], [$this->userTopicOf($character), 'error']],
            array_map(
                static fn (array $update): array => [$update[0], $update[1]['status']],
                array_values(array_filter(
                    $this->publishedMercureUpdates,
                    static fn (array $update): bool => $update[1]['syncType'] === 'industry-jobs',
                )),
            ),
        );
    }

    public static function affiliationReadFailures(): iterable
    {
        yield 'E3 service unavailable 503' => [EsiApiException::fromResponse(503, 'Service Unavailable', '/characters/12345/')];
        yield 'E4 error limited 420 after the client retry' => [EsiApiException::fromResponse(420, 'Error limited', '/corporations/98000002/')];
        yield 'E5 character response without corporation_id' => [new \UnexpectedValueException("ESI response /characters/12345/ has no integer 'corporation_id'")];
        yield 'not found 404' => [EsiApiException::notFound(endpoint: '/alliances/99000001/')];
        yield 'network error without cached copy' => [EsiApiException::fromResponse(0, 'Network error: connection reset', '/characters/12345/')];
    }

    #[Group('issue-211')]
    public function testCharacterMovedOutOfACorporationWhereItLostItsRolesNeverReadsThatCorporationAgain(): void
    {
        [$character, $stalePersonalJob] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME);
        $this->forbiddenCorporationJobsEndpoints = [self::CORPORATION_JOBS_ENDPOINT];
        $this->givenCorporationJobs([$this->corporationJobInNewCorporation()]);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([self::OTHER_CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame([], $this->loggedWarnings());
        $this->assertSame(
            ['delivered', $stalePersonalJob->getEndDate()->format('c')],
            [$stalePersonalJob->getStatus(), $stalePersonalJob->getCompletedDate()?->format('c')],
        );
    }

    #[Group('issue-211')]
    public function testCharacterWithoutRolesInItsNewCorporationKeepsTheNewAffiliationAndMarksNoJobDelivered(): void
    {
        [$character, $stalePersonalJob] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME);
        $this->forbiddenCorporationJobsEndpoints = [self::OTHER_CORPORATION_JOBS_ENDPOINT];

        $this->service->syncCharacterJobs($character);

        $this->assertSame([self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME, null, null], $this->affiliationOf($character));
        $this->assertSame([self::OTHER_CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertCount(1, $this->loggedWarnings());
        $this->assertSame(['active', null], [$stalePersonalJob->getStatus(), $stalePersonalJob->getCompletedDate()]);
        $this->assertSame(1, $this->flushCount);
    }

    #[Group('issue-211')]
    public function testCorporationJobsReadFailureOfTheNewCorporationPropagatesWithoutFlush(): void
    {
        [$character, $stalePersonalJob] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME);
        $serviceUnavailable = EsiApiException::fromResponse(503, 'Service Unavailable', self::OTHER_CORPORATION_JOBS_ENDPOINT);
        $this->givenCorporationJobsReadOutcomes($serviceUnavailable);

        $this->assertSame($serviceUnavailable, $this->syncCatchingFailure($character));
        $this->assertSame([self::OTHER_CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame(0, $this->flushCount);
        $this->assertSame(['active', null], [$stalePersonalJob->getStatus(), $stalePersonalJob->getCompletedDate()]);
    }

    #[Group('issue-211')]
    public function testCharacterJoiningAnAllianceTakesTheAllianceRead(): void
    {
        [$character] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME, self::NEW_ALLIANCE_ID, self::NEW_ALLIANCE_NAME);

        $this->service->syncCharacterJobs($character);

        $this->assertSame(
            [self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME, self::NEW_ALLIANCE_ID, self::NEW_ALLIANCE_NAME],
            $this->affiliationOf($character),
        );
    }

    #[Group('issue-211')]
    public function testCharacterLeavingItsAllianceHasNoAllianceAnymore(): void
    {
        [$character] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $character->setAllianceId(self::NEW_ALLIANCE_ID)->setAllianceName(self::NEW_ALLIANCE_NAME);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME, null, null], $this->affiliationOf($character));
    }

    #[Group('issue-211')]
    public function testCharacterWithoutCorporationJobsScopeTakesItsNewCorporationWithoutReadingCorporationJobs(): void
    {
        [$character] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME, null, null], $this->affiliationOf($character));
        $this->assertSame([self::CHARACTER_A_ID], $this->characterInfoReads);
        $this->assertSame([], $this->corporationJobsReads);
        $this->assertSame(1, $this->flushCount);
    }

    #[Group('issue-211')]
    public function testCharacterWithoutTokenDoesNotReadItsAffiliation(): void
    {
        $character = (new Character())
            ->setEveCharacterId(self::CHARACTER_A_ID)
            ->setName('Spec Industrialist')
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName(self::OLD_CORPORATION_NAME);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME);

        $this->service->syncCharacterJobs($character);

        $this->assertSame([], $this->characterInfoReads);
        $this->assertSame([self::CORPORATION_ID, self::OLD_CORPORATION_NAME, null, null], $this->affiliationOf($character));
    }

    #[Group('issue-211')]
    public function testWarmCacheOfTheOldCorporationIsNotServedToCharacterMovedToTheNewCorporation(): void
    {
        $characterC = $this->createCharacterWithUser(self::CHARACTER_C_ID);
        [$character] = $this->givenCharacterOfOldCorporationWithStalePersonalJob(self::CHARACTER_JOBS_SCOPE, self::CORPORATION_JOBS_SCOPE);
        $this->givenAffiliationReadFromEsi(self::CHARACTER_A_ID, self::OTHER_CORPORATION_ID, self::NEW_CORPORATION_NAME);
        $this->givenCorporationJobsReadOutcomes(
            $this->corporationJobsResponse([
                $this->corporationJobInOldCorporation(),
                $this->corporationJobOfSpec213(self::CORPORATION_JOB_OF_C_ID, self::CHARACTER_C_ID, 'ready', self::NITROGEN_FUEL_BLOCK_TYPE_ID, 6, 3_100_000.5),
            ]),
            $this->corporationJobsResponse([$this->corporationJobInNewCorporation()]),
        );
        $this->service->syncCharacterJobs($characterC);
        $this->forgetRecordedSideEffects();

        $this->service->syncCharacterJobs($character);

        $this->assertSame([self::CHARACTER_C_ID, self::CHARACTER_A_ID], $this->characterInfoReads);
        $this->assertSame([self::CORPORATION_JOBS_ENDPOINT, self::OTHER_CORPORATION_JOBS_ENDPOINT], $this->corporationJobsReads);
        $this->assertSame([[self::CORPORATION_JOB_IN_NEW_CORPORATION_ID, 3, 'active', self::RIFTER_TYPE_ID, 4_200_000.25]], $this->persistedJobStates());
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function characterServiceReadingRecordedAffiliations(): CharacterService
    {
        $characterService = $this->createStub(CharacterService::class);
        $characterService->method('getCharacterInfo')->willReturnCallback(
            function (int $eveCharacterId): CharacterInfoDto {
                $this->characterInfoReads[] = $eveCharacterId;
                $this->esiReadsInOrder[] = "affiliation of {$eveCharacterId}";
                $outcome = $this->characterInfoReadOutcomes[$eveCharacterId]
                    ?? new CharacterInfoDto($eveCharacterId, "Char{$eveCharacterId}", self::CORPORATION_ID, self::OLD_CORPORATION_NAME);
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            },
        );

        return $characterService;
    }

    private function givenAffiliationReadFromEsi(int $eveCharacterId, int $corporationId, string $corporationName, ?int $allianceId = null, ?string $allianceName = null): void
    {
        $this->characterInfoReadOutcomes[$eveCharacterId] = new CharacterInfoDto(
            $eveCharacterId,
            "Char{$eveCharacterId}",
            $corporationId,
            $corporationName,
            $allianceId,
            $allianceName,
        );
    }

    private function corporationNameOf(int $corporationId): string
    {
        return $corporationId === self::OTHER_CORPORATION_ID ? self::NEW_CORPORATION_NAME : self::OLD_CORPORATION_NAME;
    }

    private function givenCharacterOfOldCorporationWithStalePersonalJob(string ...$scopes): array
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString('0199a1f0-0000-7000-8000-000000000211'));
        $token = $this->createStub(EveToken::class);
        $token->method('hasScope')->willReturnCallback(
            static fn (string $scope): bool => in_array($scope, $scopes, true),
        );
        $character = (new Character())
            ->setEveCharacterId(self::CHARACTER_A_ID)
            ->setName('Spec Industrialist')
            ->setCorporationId(self::CORPORATION_ID)
            ->setCorporationName(self::OLD_CORPORATION_NAME)
            ->setUser($user)
            ->setEveToken($token);
        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));

        $this->esiClient->method('get')->willReturnCallback(function (string $endpoint): array {
            $this->personalJobsReads[] = $endpoint;
            $this->esiReadsInOrder[] = $endpoint;

            return [];
        });
        $stalePersonalJob = $this->makeCachedJob(self::STALE_PERSONAL_JOB_ID, $character, 'active', '-3 hours');
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([$stalePersonalJob]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->recordPersistedJobs();
        $this->em->method('flush')->willReturnCallback(function (): void {
            ++$this->flushCount;
        });

        return [$character, $stalePersonalJob];
    }

    private function corporationJobInNewCorporation(): array
    {
        return $this->corporationJobOfSpec213(self::CORPORATION_JOB_IN_NEW_CORPORATION_ID, self::CHARACTER_A_ID, 'active', self::RIFTER_TYPE_ID, 3, 4_200_000.25);
    }

    private function corporationJobInOldCorporation(): array
    {
        return $this->corporationJobOfSpec213(self::CORPORATION_JOB_IN_OLD_CORPORATION_ID, self::CHARACTER_A_ID, 'active', self::TRITANIUM_TYPE_ID, 8, 980_000.0);
    }

    private function affiliationOf(Character $character): array
    {
        return [$character->getCorporationId(), $character->getCorporationName(), $character->getAllianceId(), $character->getAllianceName()];
    }

    private function persistedJobStates(): array
    {
        return array_map(
            static fn (CachedIndustryJob $job): array => [$job->getJobId(), $job->getRuns(), $job->getStatus(), $job->getProductTypeId(), $job->getCost()],
            $this->persistedJobs,
        );
    }

    private function industryJobsSyncErrorEvents(): array
    {
        $events = [];
        foreach ($this->publishedMercureUpdates as [$topic, $payload]) {
            if ($payload['syncType'] === 'industry-jobs' && $payload['status'] === 'error') {
                $events[] = [$topic, $payload['message']];
            }
        }

        return $events;
    }

    private function givenCorporationJobs(array $corporationJobs): void
    {
        $this->givenCorporationJobsReadOutcomes($this->corporationJobsResponse($corporationJobs));
    }

    private function givenCorporationJobsReadOutcomes(PaginatedEsiResponse|\Throwable ...$outcomes): void
    {
        $this->corporationJobsReadOutcomes = $outcomes;
    }

    private function corporationJobsResponse(array $corporationJobs): PaginatedEsiResponse
    {
        return new PaginatedEsiResponse(
            $corporationJobs,
            new \DateTimeImmutable(sprintf('+%d seconds', self::ESI_EXPIRES_AFTER_SECONDS)),
        );
    }

    private function corporationJobsOfSpec213(): array
    {
        return [
            $this->corporationJobOfSpec213(self::CORPORATION_JOB_OF_A_ID, self::CHARACTER_A_ID, 'active', self::RIFTER_TYPE_ID, 10, 1_250_000.75),
            $this->corporationJobOfSpec213(self::CORPORATION_JOB_OF_C_ID, self::CHARACTER_C_ID, 'ready', self::TRITANIUM_TYPE_ID, 4, 2_340_000.0),
            $this->corporationJobOfSpec213(self::CORPORATION_JOB_OF_D_ID, self::CHARACTER_D_WITHOUT_ROLES_ID, 'active', self::NITROGEN_FUEL_BLOCK_TYPE_ID, 6, 3_100_000.5),
            $this->corporationJobOfSpec213(self::CORPORATION_JOB_OF_OUTSIDER_ID, self::THIRD_CORPMATE_ID, 'active', self::TYPE_ID_MISSING_FROM_SDE, 8, 450_000.0),
        ];
    }

    private function corporationJobOfSpec213(int $jobId, int $installerId, string $status, int $productTypeId, int $runs, float $cost): array
    {
        $jobData = $this->makeJobData($jobId, $installerId, $status, $productTypeId);
        $jobData['runs'] = $runs;
        $jobData['cost'] = $cost;

        return $jobData;
    }

    private function recordPersistedJobs(): void
    {
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedJobs[] = $entity;
        });
    }

    private function storedCacheEntries(): array
    {
        return array_filter($this->esiCache->getValues(), static fn (mixed $value): bool => $value !== null);
    }

    private function forgetRecordedSideEffects(): void
    {
        $this->persistedJobs = [];
        $this->publishedMercurePayloads = [];
        $this->publishedMercureUpdates = [];
        $this->dispatchedNotifications = [];
        $this->notificationRecipients = [];
        $this->completionNotificationEvents = [];
    }

    private function cachedJobSnapshot(CachedIndustryJob $job): array
    {
        return [
            $job->getStatus(),
            $job->getCharacter(),
            $job->getRuns(),
            $job->getCost(),
            $job->getEndDate()->format('c'),
            $job->getCompletedDate()?->format('c'),
            $job->getCachedAt()->format('U.u'),
            $job->getCompletionNotifiedAt()?->format('c'),
        ];
    }

    private function givenNoCachedJobAndRecordedPersists(): void
    {
        $this->stubCachedJobs([]);
        $this->stubActiveJobsByCharacter([]);
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturn([]);
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persistedJobs[] = $entity;
        });
    }

    private function esiManufacturingJob500(string $status): array
    {
        $jobData = $this->makeJobData(self::MANUFACTURING_JOB_ID, self::CHARACTER_A_ID, $status, self::RIFTER_TYPE_ID);
        $jobData['blueprint_type_id'] = self::RIFTER_BLUEPRINT_TYPE_ID;
        $jobData['runs'] = 10;
        $jobData['cost'] = 1_250_000.75;

        return $jobData;
    }

    private function esiMeResearchJob501(string $status): array
    {
        $jobData = $this->makeJobData(self::ME_RESEARCH_JOB_ID, self::CHARACTER_A_ID, $status);
        $jobData['activity_id'] = self::ME_RESEARCH_ACTIVITY_ID;
        $jobData['blueprint_type_id'] = self::RIFTER_BLUEPRINT_TYPE_ID;
        $jobData['runs'] = 2;
        unset($jobData['product_type_id'], $jobData['cost']);

        return $jobData;
    }

    private function cachedRifterJob(int $jobId, Character $character, int $activityId, ?int $productTypeId, int $runs, ?float $cost): CachedIndustryJob
    {
        return $this->makeCachedJob($jobId, $character, 'active', '+1 day')
            ->setActivityId($activityId)
            ->setBlueprintTypeId(self::RIFTER_BLUEPRINT_TYPE_ID)
            ->setProductTypeId($productTypeId)
            ->setRuns($runs)
            ->setCost($cost);
    }

    private function cachedJobStateOf(CachedIndustryJob $job): array
    {
        return [$job->getJobId(), $job->getBlueprintTypeId(), $job->getRuns(), $job->getCost(), $job->getProductTypeId()];
    }

    private function corporationJobsOfE1(): array
    {
        return [
            $this->makeJobData(900, self::CHARACTER_A_ID, 'active'),
            $this->makeJobData(901, self::CHARACTER_A_ID, 'ready'),
            $this->makeJobData(902, self::FIRST_CORPMATE_ID, 'active'),
            $this->makeJobData(903, self::SECOND_CORPMATE_ID, 'active'),
            $this->makeJobData(904, self::THIRD_CORPMATE_ID, 'ready'),
        ];
    }

    private function userTopicOf(Character $character): string
    {
        return sprintf('/user/%s/sync/industry-jobs', $character->getUser()->getId()->toRfc4122());
    }

    private function industryJobsSyncProgressEvents(): array
    {
        $events = [];
        foreach ($this->publishedMercureUpdates as [$topic, $payload]) {
            if ($payload['syncType'] === 'industry-jobs' && $payload['status'] === 'in_progress') {
                $events[] = [$topic, $payload['progress'], $payload['message']];
            }
        }

        return $events;
    }

    private function industryJobsSyncCompletedEvents(): array
    {
        $events = [];
        foreach ($this->publishedMercureUpdates as [$topic, $payload]) {
            if ($payload['syncType'] === 'industry-jobs' && $payload['status'] === 'completed') {
                $events[] = [$topic, $payload['message'], $payload['data']];
            }
        }

        return $events;
    }

    private function makeTritaniumJobData(int $jobId, int $installerId, string $status): array
    {
        $jobData = $this->makeJobData($jobId, $installerId, $status, self::TRITANIUM_TYPE_ID);
        $jobData['runs'] = 10;
        $jobData['cost'] = 2_340_000.0;

        return $jobData;
    }

    private function recordDispatchedNotifications(?int $failingJobId = null, ?\Throwable $failure = null): void
    {
        $this->notificationDispatcher->method('dispatch')->willReturnCallback(
            function (User $recipient, mixed ...$notification) use ($failingJobId, $failure): void {
                $jobId = $notification[4]['jobId'];
                $this->completionNotificationEvents[] = ['dispatch', $jobId];
                if ($jobId === $failingJobId && $failure !== null) {
                    throw $failure;
                }
                $this->notificationRecipients[] = $recipient;
                $this->dispatchedNotifications[] = $notification;
            },
        );
    }

    private function assertReservedDuringSync(CachedIndustryJob $job, \DateTimeImmutable $syncStartedAt): void
    {
        $completionNotifiedAt = $job->getCompletionNotifiedAt();
        $this->assertNotNull($completionNotifiedAt);
        $this->assertGreaterThanOrEqual($syncStartedAt, $completionNotifiedAt);
        $this->assertLessThanOrEqual(new \DateTimeImmutable(), $completionNotifiedAt);
    }

    private function jobIdsOfMercureJobCompletedNotifications(): array
    {
        return array_map(
            static fn (array $notification): int => $notification['data']['jobId'],
            $this->mercureNotificationsOf('industry-job-completed'),
        );
    }

    private function mercureSyncErrorMessages(): array
    {
        return array_column(array_values(array_filter(
            $this->publishedMercurePayloads,
            static fn (array $payload): bool => $payload['syncType'] === 'industry-jobs' && $payload['status'] === 'error',
        )), 'message');
    }

    private function givenMatchedCachedJob(Character $character): CachedIndustryJob
    {
        $cachedJob = $this->makeCachedJob(100, $character, 'active', '2026-10-20T12:00:00Z');
        $cachedJob->setCost(1_500_000.50)->setRuns(10);

        return $cachedJob;
    }

    private function makeStep(string $jobMatchMode): IndustryProjectStep
    {
        $project = $this->createStub(IndustryProject::class);
        $project->method('getId')->willReturn(Uuid::fromString('0193a5c2-7b1e-7c4d-9f2a-3e8b6d1c5a41'));
        $project->method('getName')->willReturn('Capital Batch');

        return (new IndustryProjectStep())
            ->setProject($project)
            ->setProductTypeId(self::TYPE_ID_MISSING_FROM_SDE)
            ->setJobMatchMode($jobMatchMode);
    }

    private function givenJobMatchOnStep(int $esiJobId, IndustryProjectStep $step): IndustryStepJobMatch
    {
        $jobMatch = (new IndustryStepJobMatch())
            ->setEsiJobId($esiJobId)
            ->setStatus('active')
            ->setEndDate(new \DateTimeImmutable('2026-10-20T12:00:00Z'))
            ->setCost(1_500_000.50)
            ->setRuns(10)
            ->setCharacterName('Main Industrialist')
            ->setFacilityId(1_035_466_617_946)
            ->setFacilityName('Sotiyo Kemerk')
            ->setPlannedStructureName('Azbel Planned')
            ->setPlannedMaterialBonus(1.0)
            ->setMatchedAt(new \DateTimeImmutable('2026-10-15T09:00:00Z'));
        $step->addJobMatch($jobMatch);
        $this->jobMatches[] = $jobMatch;
        $this->jobMatchRepository->method('findByEsiJobIds')->willReturnCallback(
            fn (array $esiJobIds): array => array_values(array_filter(
                $this->jobMatches,
                static fn (IndustryStepJobMatch $match): bool => in_array($match->getEsiJobId(), $esiJobIds, true),
            )),
        );

        return $jobMatch;
    }

    /**
     * @param list<CachedIndustryJob> $cachedJobs
     */
    private function stubCachedJobs(array $cachedJobs): void
    {
        $this->jobRepository->method('findByJobId')->willReturnCallback(
            static function (int $jobId) use ($cachedJobs): ?CachedIndustryJob {
                foreach ($cachedJobs as $cachedJob) {
                    if ($cachedJob->getJobId() === $jobId) {
                        return $cachedJob;
                    }
                }

                return null;
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeEsiJobOfMatch(int $jobId, int $installerId, string $status, string $endDate): array
    {
        $esiJob = $this->makeJobData($jobId, $installerId, $status);
        $esiJob['end_date'] = $endDate;
        $esiJob['runs'] = 10;
        $esiJob['cost'] = 1_500_000.50;

        return $esiJob;
    }

    /**
     * @return array{string, ?string, ?float, int}
     */
    private function jobStateOf(IndustryStepJobMatch $jobMatch): array
    {
        return [$jobMatch->getStatus(), $jobMatch->getEndDate()?->format('c'), $jobMatch->getCost(), $jobMatch->getRuns()];
    }


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

    private function createCharacterWithUser(int $eveCharacterId, int $corporationId = self::CORPORATION_ID): Character
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
        $character->method('getCorporationId')->willReturn($corporationId);

        $user->method('getCharacters')->willReturn(new ArrayCollection([$character]));
        $this->givenAffiliationReadFromEsi($eveCharacterId, $corporationId, $this->corporationNameOf($corporationId));

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
        $job->setRuns(5);
        $job->setCost(100_000.0);

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
                    && in_array($job->getStatus(), ['active', 'paused', 'ready'], true),
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
