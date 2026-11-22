<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectLineReservation;
use App\Entity\IndustryProjectParticipant;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\IndustryProjectLineReservationRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\LineMatchFailure;
use App\Service\Industry\Project\ProjectContainerScope;
use App\Service\Industry\Project\ProjectContainerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IndustryJobMatcher::class)]
#[Group('issue-362')]
#[AllowMockObjectsWithoutExpectations]
final class IndustryJobMatcherTest extends TestCase
{
    private const int ISHTAR_TYPE_ID = 12005;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 12006;
    private const int ISHTAR_FINAL_LINE_RUNS = 5;
    private const int RAM_TYPE_ID = 11478;
    private const int RAM_BLUEPRINT_TYPE_ID = 11479;
    private const int RAM_COMPONENT_LINE_RUNS = 4;
    private const int FERROGEL_TYPE_ID = 16683;
    private const int FERROGEL_FORMULA_TYPE_ID = 17958;
    private const int FERROGEL_REACTION_LINE_RUNS = 6;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int ESI_REACTION_ACTIVITY_ID = 9;
    private const int SOTIYO_STATION_ID = 1_035_466_617_946;
    private const int OWNER_CONTAINER_ID = 1_040_000_000_001;
    private const int OTHER_CONTAINER_ID = 1_040_000_000_002;
    private const int HANGAR_LOCATION_ID = 60_003_760;
    private const string PROJECT_CREATED_AT = '2026-11-01 10:00:00';
    private const string OLDER_PROJECT_CREATED_AT = '2026-11-01 08:00:00';
    private const string NEWER_PROJECT_CREATED_AT = '2026-11-01 12:00:00';
    private const int ARIA_EVE_CHARACTER_ID = 2_112_000_101;
    private const int ARIA_ALT_EVE_CHARACTER_ID = 2_112_000_102;

    private CachedIndustryJobRepository $jobRepository;
    private IndustryProjectJobMatchRepository $jobMatchRepository;
    private IndustryActivityProductRepository $productRepository;
    private IndustryJobMatcher $matcher;
    private BufferingLogger $logger;
    private ?IndustryProjectJobMatch $unremovableMatch = null;

    private array $jobsByBlueprint = [];

    private array $jobsMatchedToProjects = [];

    private array $projectsInPlay = [];

    private array $jobQueries = [];

    private array $scopesByProjectId = [];

    private array $reservationsByProjectId = [];

    private array $structureConfigsById = [];

    private User $owner;
    private Character $mainCharacter;
    private Character $altCharacter;

    protected function setUp(): void
    {
        $this->jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $this->jobRepository->method('findManufacturingJobsByBlueprint')->willReturnCallback(
            function (int $blueprintTypeId, array $characterIds, ?int $runs, \DateTimeImmutable $startedSince): array {
                $this->jobQueries[] = [$blueprintTypeId, $characterIds, $runs, $startedSince];

                return array_values(array_filter(
                    $this->jobsByBlueprint[$blueprintTypeId] ?? [],
                    static fn (CachedIndustryJob $job): bool => $job->getStartDate() >= $startedSince
                        && \in_array($job->getCharacter()->getId(), $characterIds, true),
                ));
            },
        );

        $this->jobMatchRepository = $this->createMock(IndustryProjectJobMatchRepository::class);
        $this->jobMatchRepository->method('findEsiJobIdsMatchedToOtherProjects')->willReturnCallback(
            fn (IndustryProject $project): array => [
                ...array_values(array_map(
                    static fn (array $matched): int => $matched[1],
                    array_filter($this->jobsMatchedToProjects, static fn (array $matched): bool => $matched[0] !== $project),
                )),
                ...$this->jobIdsHeldByTheOtherProjectsInPlay($project),
            ],
        );

        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->productRepository->method('producerOf')->willReturnCallback(
            static fn (int $productTypeId): IndustryActivityProduct => match ($productTypeId) {
                self::ISHTAR_TYPE_ID => self::producer(self::ISHTAR_BLUEPRINT_TYPE_ID, IndustryActivityType::Manufacturing, self::ISHTAR_TYPE_ID),
                self::RAM_TYPE_ID => self::producer(self::RAM_BLUEPRINT_TYPE_ID, IndustryActivityType::Manufacturing, self::RAM_TYPE_ID),
                self::FERROGEL_TYPE_ID => self::producer(self::FERROGEL_FORMULA_TYPE_ID, IndustryActivityType::Reaction, self::FERROGEL_TYPE_ID),
            },
        );

        $calculationService = $this->createStub(IndustryCalculationService::class);
        $calculationService->method('resolveFacilityName')->willReturnCallback(
            static fn (int $stationId): string => 'Sotiyo '.$stationId,
        );

        $containerService = $this->createStub(ProjectContainerService::class);
        $containerService->method('containerScopeOf')->willReturnCallback(
            fn (IndustryProject $project): ProjectContainerScope => $this->scopesByProjectId[$project->getId()->toRfc4122()] ?? new ProjectContainerScope('no_container_name'),
        );

        $this->logger = new BufferingLogger();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('remove')->willReturnCallback(function (object $entity): void {
            if ($entity === $this->unremovableMatch) {
                throw new \RuntimeException('remove failed');
            }
        });

        $reservationRepository = $this->createStub(IndustryProjectLineReservationRepository::class);
        $reservationRepository->method('findByProject')->willReturnCallback(
            fn (IndustryProject $project): array => $this->reservationsByProjectId[$project->getId()->toRfc4122()] ?? [],
        );

        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('find')->willReturnCallback(
            function (mixed $id): ?IndustryStructureConfig {
                if (!Uuid::isValid((string) $id)) {
                    throw new \InvalidArgumentException('Invalid uuid passed to the structure configuration lookup');
                }

                return $this->structureConfigsById[(string) $id] ?? null;
            },
        );

        $this->matcher = new IndustryJobMatcher(
            $calculationService,
            $this->jobRepository,
            $this->productRepository,
            $this->jobMatchRepository,
            $entityManager,
            $this->logger,
            $containerService,
            $reservationRepository,
            $structureConfigRepository,
        );

        $this->owner = new User();
        $this->mainCharacter = $this->character('Aria Solberg');
        $this->altCharacter = $this->character('Aria Alt');
        $this->owner->addCharacter($this->mainCharacter);
        $this->owner->addCharacter($this->altCharacter);
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testFinalLineIsMatchedToTheJobsStartedSinceTheProjectCreationThatFitItsRuns(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_362_004, 4, '2026-10-31 09:00:00'),
            $this->job(7_362_003, 3, '2026-11-02 08:00:00'),
            $this->job(7_362_002, 2, '2026-11-03 08:00:00', $this->altCharacter),
        );

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_362_002, 7_362_003], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(5, $ishtarLine->getMatchedRuns());
        self::assertSame(0, $ishtarLine->getFulfilledQuantity());
        self::assertCount(1, $this->jobQueries);
        self::assertSame(
            [self::ISHTAR_BLUEPRINT_TYPE_ID, [$this->mainCharacter->getId(), $this->altCharacter->getId()], null, '2026-11-01T10:00:00+00:00'],
            [$this->jobQueries[0][0], $this->jobQueries[0][1], $this->jobQueries[0][2], $this->jobQueries[0][3]->format('c')],
        );
    }

    #[Group('issue-1124')]
    public function testAJobLargerThanTheRemainingRunsOfTheLineIsNeverMatchedAutomatically(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_362_013, 3, '2026-11-02 08:00:00'),
            $this->job(7_362_017, 7, '2026-11-02 09:00:00'),
            $this->job(7_362_018, 8, '2026-11-02 10:00:00'),
        );

        $this->matcher->matchOpenProjects([$this->project($ishtarLine)]);

        self::assertSame([7_362_013], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(3, $ishtarLine->getMatchedRuns());
    }

    public function testJobAlreadyMatchedToAnotherProjectIsNotMatchedToTheLine(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $this->jobsMatchedToProjects[] = [new IndustryProject(), 7_362_023];
        $this->jobsMatchedToProjects[] = [$project, 7_362_022];
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_362_023, 3, '2026-11-02 08:00:00'),
            $this->job(7_362_022, 2, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_362_022], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(2, $ishtarLine->getMatchedRuns());
    }

    public function testLineWithAManualLinkKeepsItsSingleManualJobWhenJobsAreMatched(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $manualJob = $this->job(7_362_031, 3, '2026-11-02 08:00:00');
        $this->matcher->linkJobToBomLine($manualJob, $ishtarLine);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $manualJob,
            $this->job(7_362_032, 2, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_362_031], $this->matchedJobIdsOf($ishtarLine));
        self::assertTrue($ishtarLine->getJobMatches()->first()->isManual());
        self::assertSame(3, $ishtarLine->getMatchedRuns());
    }

    public function testMatchedJobCopiesTheEsiJobStateAndIsNotManual(): void
    {
        $ramLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', self::RAM_COMPONENT_LINE_RUNS, 'manufacturing');
        $this->givenJobs(
            self::RAM_BLUEPRINT_TYPE_ID,
            $this->job(7_362_041, 4, '2026-11-02 08:00:00', $this->altCharacter, 'delivered', 86_250.75, self::SOTIYO_STATION_ID),
        );

        $this->matcher->matchOpenProjects([$this->project($ramLine)]);

        $match = $ramLine->getJobMatches()->first();
        self::assertInstanceOf(IndustryProjectJobMatch::class, $match);
        self::assertSame(
            [7_362_041, 4, 86_250.75, 'delivered', '2026-11-04T08:00:00+00:00', 'Aria Alt', self::SOTIYO_STATION_ID, 'Sotiyo 1035466617946', false, $ramLine],
            [$match->getEsiJobId(), $match->getRuns(), $match->getCost(), $match->getStatus(), $match->getEndDate()?->format('c'), $match->getCharacterName(), $match->getFacilityId(), $match->getFacilityName(), $match->isManual(), $match->getBomLine()],
        );
    }

    public function testCancelledJobIsNotMatchedToTheLine(): void
    {
        $ramLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', self::RAM_COMPONENT_LINE_RUNS, 'manufacturing');
        $this->givenJobs(
            self::RAM_BLUEPRINT_TYPE_ID,
            $this->job(7_362_051, 4, '2026-11-02 08:00:00', status: 'cancelled'),
            $this->job(7_362_052, 1, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchOpenProjects([$this->project($ramLine)]);

        self::assertSame([7_362_052], $this->matchedJobIdsOf($ramLine));
    }

    public function testReactionLineIsMatchedToTheJobsOfItsFormula(): void
    {
        $ferrogelLine = $this->jobLine(self::FERROGEL_TYPE_ID, 'Ferrogel', 'component', self::FERROGEL_REACTION_LINE_RUNS, 'reaction');
        $this->givenJobs(
            self::FERROGEL_FORMULA_TYPE_ID,
            $this->job(7_362_061, 6, '2026-11-02 08:00:00', activityId: self::ESI_REACTION_ACTIVITY_ID),
        );

        $this->matcher->matchOpenProjects([$this->project($ferrogelLine)]);

        self::assertSame([7_362_061], $this->matchedJobIdsOf($ferrogelLine));
        self::assertSame(6, $ferrogelLine->getMatchedRuns());
    }

    public function testMaterialAndInventionLinesAreNotMatched(): void
    {
        $tritaniumLine = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(12_000)->setIsJob(false);
        $inventionLine = $this->jobLine(self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 'blueprint', 3, 'invention');

        $this->matcher->matchOpenProjects([$this->project($tritaniumLine, $inventionLine)]);

        self::assertSame([], $this->jobQueries);
        self::assertSame([[], []], [$this->matchedJobIdsOf($tritaniumLine), $this->matchedJobIdsOf($inventionLine)]);
    }

    public function testMatchingTwiceKeepsASingleMatchPerJob(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_362_071, 3, '2026-11-02 08:00:00'),
            $this->job(7_362_072, 2, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchOpenProjects([$project]);
        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_362_071, 7_362_072], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(5, $ishtarLine->getMatchedRuns());
    }

    #[Group('issue-318')]
    public function testJobsOfAnAcceptedParticipantAreMatchedAlongsideTheOwnersAndKeepTheirInstaller(): void
    {
        $participantCharacter = $this->character('Sana Vel');
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $this->givenParticipant($project, ParticipantStatus::Accepted, $participantCharacter);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_318_001, 2, '2026-11-02 08:00:00'),
            $this->job(7_318_002, 3, '2026-11-03 08:00:00', $participantCharacter),
        );

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_318_001, 7_318_002], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(['Aria Solberg', 'Sana Vel'], array_map(
            static fn (IndustryProjectJobMatch $match): ?string => $match->getCharacterName(),
            $ishtarLine->getJobMatches()->toArray(),
        ));
        self::assertEqualsCanonicalizing(
            [$this->mainCharacter->getId(), $this->altCharacter->getId(), $participantCharacter->getId()],
            $this->jobQueries[0][1],
        );
    }

    #[Group('issue-318')]
    public function testCharactersOfAPendingOrRemovedParticipantAreNotRead(): void
    {
        $pendingCharacter = $this->character('Pending Pilot');
        $removedCharacter = $this->character('Removed Pilot');
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $this->givenParticipant($project, ParticipantStatus::Pending, $pendingCharacter);
        $this->givenParticipant($project, ParticipantStatus::Removed, $removedCharacter);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_318_003, 2, '2026-11-02 08:00:00', $pendingCharacter),
            $this->job(7_318_004, 3, '2026-11-03 08:00:00', $removedCharacter),
        );

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([$this->mainCharacter->getId(), $this->altCharacter->getId()], $this->jobQueries[0][1]);
    }

    #[Group('issue-318')]
    public function testTheOwnerEnrolledAsAParticipantIsReadOnce(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $project->enrolOwner();
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_318_005, 5, '2026-11-02 08:00:00'));

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([$this->mainCharacter->getId(), $this->altCharacter->getId()], $this->jobQueries[0][1]);
    }

    #[Group('issue-1123')]
    public function testReleasingAProjectRemovesTheAutomaticMatchesOfItsJobLines(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_123_001, 3, '2026-11-02 08:00:00'),
            $this->job(7_123_002, 2, '2026-11-02 09:00:00'),
        );
        $this->matcher->matchOpenProjects([$project]);
        self::assertSame([7_123_001, 7_123_002], $this->matchedJobIdsOf($ishtarLine));

        $this->matcher->releaseAutomaticMatches($project);

        self::assertSame([], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(0, $ishtarLine->getMatchedRuns());
    }

    #[Group('issue-1123')]
    public function testReleasingAProjectKeepsEveryMatchOfALineHoldingAManualOneAndReleasesTheOtherLines(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $ramLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', self::RAM_COMPONENT_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine, $ramLine);
        $manualJob = $this->job(7_123_011, 3, '2026-11-02 08:00:00');
        $this->matcher->linkJobToBomLine($manualJob, $ishtarLine);
        $this->givenJobs(self::RAM_BLUEPRINT_TYPE_ID, $this->job(7_123_012, self::RAM_COMPONENT_LINE_RUNS, '2026-11-02 09:00:00'));
        $this->matcher->matchOpenProjects([$project]);
        self::assertSame([7_123_012], $this->matchedJobIdsOf($ramLine));

        $this->matcher->releaseAutomaticMatches($project);

        self::assertSame([7_123_011], $this->matchedJobIdsOf($ishtarLine));
        self::assertTrue($ishtarLine->getJobMatches()->first()->isManual());
        self::assertSame([], $this->matchedJobIdsOf($ramLine));
    }

    #[Group('issue-1246')]
    public function testAJobFittingTheLinesOfTwoProjectsThatNothingDistinguishesGoesToTheOldestProject(): void
    {
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_124_001, 5, '2026-11-02 08:00:00'));

        $this->matcher->matchOpenProjects([$newer, $older]);

        self::assertSame([[7_124_001], []], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame([], $lineOfOlder->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1124')]
    #[DataProvider('projectOrders')]
    public function testAJobFittingOnlyOneCandidateProjectGoesToItWhateverTheProjectOrder(bool $smallLineProjectFirst): void
    {
        $smallLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 'manufacturing');
        $fittingLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $small = $this->project($smallLine);
        $fitting = $this->project($fittingLine);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_124_011, 5, '2026-11-02 08:00:00'));
        $ordered = $smallLineProjectFirst ? [$small, $fitting] : [$fitting, $small];

        $this->matcher->matchOpenProjects($ordered);

        self::assertSame([[], [7_124_011]], [$this->matchedJobIdsOf($smallLine), $this->matchedJobIdsOf($fittingLine)]);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function projectOrders(): iterable
    {
        yield 'small line first' => [true];
        yield 'fitting line first' => [false];
    }

    #[Group('issue-1124')]
    public function testEachJobFittingOneProjectOnlyIsLinkedToThatProject(): void
    {
        $lineOfSix = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 6, 'manufacturing');
        $lineOfFour = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 4, 'manufacturing');
        $sixRuns = $this->project($lineOfSix);
        $fourRuns = $this->project($lineOfFour);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_124_021, 6, '2026-11-02 08:00:00'),
            $this->job(7_124_022, 4, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchOpenProjects([$fourRuns, $sixRuns]);

        self::assertSame([[7_124_021], [7_124_022]], [$this->matchedJobIdsOf($lineOfSix), $this->matchedJobIdsOf($lineOfFour)]);
    }

    #[Group('issue-1246')]
    public function testTheJobOfSixRunsFillsTheLineOfSixThenTheJobOfFourRunsGoesToTheLineOfTen(): void
    {
        $lineOfTen = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfSix = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 6, 'manufacturing');
        $ofTen = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfTen);
        $ofSix = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfSix);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_124_031, 6, '2026-11-02 08:00:00'),
            $this->job(7_124_032, 4, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchOpenProjects([$ofTen, $ofSix]);

        self::assertSame([[7_124_032], [7_124_031]], [$this->matchedJobIdsOf($lineOfTen), $this->matchedJobIdsOf($lineOfSix)]);
        self::assertSame([['exact_fit'], []], [$lineOfSix->getJobMatches()->first()->getMatchSignals(), $lineOfTen->getJobMatches()->first()->getMatchSignals()]);
    }

    #[Group('issue-1124')]
    public function testALineHoldingAManualLinkIsNotACandidateForTheJob(): void
    {
        $manualLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $openLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $withManualLink = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $manualLine);
        $open = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $openLine);
        $this->matcher->linkJobToBomLine($this->job(7_124_051, 1, '2026-11-02 08:00:00'), $manualLine);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_124_052, 5, '2026-11-02 09:00:00'));

        $this->matcher->matchOpenProjects([$withManualLink, $open]);

        self::assertSame([[7_124_051], [7_124_052]], [$this->matchedJobIdsOf($manualLine), $this->matchedJobIdsOf($openLine)]);
    }

    #[Group('issue-1124')]
    public function testALineWithoutRunsIsReportedAndTakesNoJobFromTheLineOfAnotherProject(): void
    {
        $withoutRuns = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing')->setRuns(null);
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $withoutRunsProject = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $withoutRuns);
        $ishtarProject = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $ishtarLine);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_124_071, 5, '2026-11-02 08:00:00'));

        $failures = $this->matcher->matchOpenProjects([$withoutRunsProject, $ishtarProject]);

        self::assertSame([[], [7_124_071]], [$this->matchedJobIdsOf($withoutRuns), $this->matchedJobIdsOf($ishtarLine)]);
        self::assertSame(
            [
                $withoutRunsProject->getId()->toRfc4122() => [['typeId' => self::ISHTAR_TYPE_ID, 'typeName' => 'Ishtar', 'reason' => 'no_runs']],
                $ishtarProject->getId()->toRfc4122() => [],
            ],
            array_map(
                static fn (array $projectFailures): array => array_map(static fn (LineMatchFailure $failure): array => $failure->toArray(), $projectFailures),
                $failures,
            ),
        );
    }

    #[Group('issue-1125')]
    #[DataProvider('projectOrders')]
    public function testAJobTakingItsInputFromTheContainerOfOneProjectGoesToThatProjectWhateverTheProjectOrder(bool $containerOwnerFirst): void
    {
        $lineOfOwner = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $lineOfOther = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $owner = $this->project($lineOfOwner);
        $other = $this->project($lineOfOther);
        $this->givenContainer($owner, self::OWNER_CONTAINER_ID);
        $this->givenContainer($other, self::OTHER_CONTAINER_ID);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_001, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId(self::OWNER_CONTAINER_ID)
            ->setOutputLocationId(self::OTHER_CONTAINER_ID));
        $ordered = $containerOwnerFirst ? [$owner, $other] : [$other, $owner];

        $this->matcher->matchOpenProjects($ordered);

        self::assertSame([[7_125_001], []], [$this->matchedJobIdsOf($lineOfOwner), $this->matchedJobIdsOf($lineOfOther)]);
        self::assertSame(['place', 'exact_fit'], $lineOfOwner->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testTheContainerHoldingTheOutputOfTheJobGivesItsProjectTheJobOverTheOldestProject(): void
    {
        $lineOfFirst = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfSecond = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $first = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfFirst);
        $second = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfSecond);
        $this->givenContainer($first, self::OWNER_CONTAINER_ID);
        $this->givenContainer($second, self::OTHER_CONTAINER_ID);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_011, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId(self::HANGAR_LOCATION_ID)
            ->setOutputLocationId(self::OTHER_CONTAINER_ID));

        $this->matcher->matchOpenProjects([$first, $second]);

        self::assertSame([[], [7_125_011]], [$this->matchedJobIdsOf($lineOfFirst), $this->matchedJobIdsOf($lineOfSecond)]);
        self::assertSame(['place'], $lineOfSecond->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1125')]
    #[DataProvider('inputsOutsideEveryProjectContainer')]
    public function testAJobWhoseInputIsOutsideEveryProjectContainerKeepsTheUniqueCandidateRule(?int $inputLocationId): void
    {
        $smallLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 'manufacturing');
        $fittingLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $small = $this->project($smallLine);
        $fitting = $this->project($fittingLine);
        $this->givenContainer($small, self::OWNER_CONTAINER_ID);
        $this->givenContainer($fitting, self::OTHER_CONTAINER_ID);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_021, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId($inputLocationId));

        $this->matcher->matchOpenProjects([$small, $fitting]);

        self::assertSame([[], [7_125_021]], [$this->matchedJobIdsOf($smallLine), $this->matchedJobIdsOf($fittingLine)]);
    }

    /**
     * @return iterable<string, array{?int}>
     */
    public static function inputsOutsideEveryProjectContainer(): iterable
    {
        yield 'hangar' => [self::HANGAR_LOCATION_ID];
        yield 'unresolved input' => [null];
    }

    #[Group('issue-1246')]
    public function testAJobTakingItsInputFromASharedContainerGoesToTheOldestOfTheProjectsUsingIt(): void
    {
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $this->givenContainer($older, self::OWNER_CONTAINER_ID);
        $this->givenContainer($newer, self::OWNER_CONTAINER_ID);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_031, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId(self::OWNER_CONTAINER_ID));

        $this->matcher->matchOpenProjects([$newer, $older]);

        self::assertSame([[7_125_031], []], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame(['place'], $lineOfOlder->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1125')]
    public function testTheContainerOfAProjectWithAnUnknownScopeExcludesNoJobFromTheOtherProjects(): void
    {
        $lineOfActive = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $active = $this->project($lineOfActive);
        $unknown = $this->project($this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 'manufacturing'));
        $this->scopesByProjectId[$unknown->getId()->toRfc4122()] = new ProjectContainerScope('corporation_assets_not_synced', [self::OTHER_CONTAINER_ID]);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_041, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId(self::OTHER_CONTAINER_ID));

        $this->matcher->matchOpenProjects([$active, $unknown]);

        self::assertSame([7_125_041], $this->matchedJobIdsOf($lineOfActive));
        self::assertSame(['exact_fit'], $lineOfActive->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1125')]
    public function testAJobTakingItsInputFromTheContainerOfAProjectIsNotOfferedToAnotherProjectWhenItDoesNotFitTheOwner(): void
    {
        $lineOfOwner = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 'manufacturing');
        $lineOfOther = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $owner = $this->project($lineOfOwner);
        $other = $this->project($lineOfOther);
        $this->givenContainer($owner, self::OWNER_CONTAINER_ID);
        $this->givenContainer($other, self::OTHER_CONTAINER_ID);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_125_051, 5, '2026-11-02 08:00:00')->setBlueprintLocationId(self::OWNER_CONTAINER_ID),
            $this->job(7_125_052, 3, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchOpenProjects([$owner, $other]);

        self::assertSame([[], [7_125_052]], [$this->matchedJobIdsOf($lineOfOwner), $this->matchedJobIdsOf($lineOfOther)]);
    }

    #[Group('issue-1245')]
    public function testAJobLinkedToAnOpenProjectStaysOnItsLineWhenTwoOpenProjectsShareTheBlueprintAndTheSyncRunsAgain(): void
    {
        $lineOfFirst = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfSecond = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $first = $this->project($lineOfFirst);
        $second = $this->project($lineOfSecond);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_245_001, 5, '2026-11-02 08:00:00'));
        $this->matcher->matchOpenProjects([$first]);

        $failures = $this->matcher->matchOpenProjects([$second, $first]);

        self::assertSame([$second->getId()->toRfc4122() => [], $first->getId()->toRfc4122() => []], $failures);
        self::assertSame([7_245_001], $this->matchedJobIdsOf($lineOfFirst));
        self::assertSame([], $this->matchedJobIdsOf($lineOfSecond));
    }

    #[Group('issue-1245')]
    public function testRemainingRunsOfALineCountTheKeptMatchesSoAJobTooLargeForTheRemainderIsNotAddedButASmallerOneIs(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $project = $this->project($ishtarLine);
        $keptJob = $this->job(7_245_011, 4, '2026-11-03 08:00:00');
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $keptJob);
        $this->matcher->matchOpenProjects([$project]);
        $largeJob = $this->job(7_245_012, 7, '2026-11-02 08:00:00');
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $largeJob, $keptJob);

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_245_011], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(4, $ishtarLine->getMatchedRuns());

        $smallJob = $this->job(7_245_013, 6, '2026-11-04 08:00:00');
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $largeJob, $keptJob, $smallJob);

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_245_011, 7_245_013], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(10, $ishtarLine->getMatchedRuns());
    }

    #[Group('issue-1245')]
    public function testMatchingRemovesTheCancelledAndRevertedMatchesAndKeepsTheOthers(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 20, 'manufacturing');
        $project = $this->project($ishtarLine);
        $cancelledJob = $this->job(7_245_021, 3, '2026-11-02 08:00:00');
        $revertedJob = $this->job(7_245_022, 4, '2026-11-02 09:00:00');
        $deliveredJob = $this->job(7_245_024, 2, '2026-11-02 11:00:00');
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $cancelledJob,
            $revertedJob,
            $this->job(7_245_023, 5, '2026-11-02 10:00:00'),
            $deliveredJob,
        );
        $this->matcher->matchOpenProjects([$project]);
        $this->givenMatchStatus($ishtarLine, 7_245_021, 'cancelled');
        $this->givenMatchStatus($ishtarLine, 7_245_022, 'reverted');
        $this->givenMatchStatus($ishtarLine, 7_245_024, 'delivered');
        $cancelledJob->setStatus('cancelled');
        $revertedJob->setStatus('reverted');
        $deliveredJob->setStatus('delivered');

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_245_023, 7_245_024], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(7, $ishtarLine->getMatchedRuns());
    }

    #[Group('issue-1245')]
    public function testMatchingJobsReleasesTheMatchesWithoutOutputItselfBeforeCountingTheRemainingRuns(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $project = $this->project($ishtarLine);
        $keptJob = $this->job(7_245_031, 3, '2026-11-02 08:00:00');
        $endedJob = $this->job(7_245_032, 2, '2026-11-03 08:00:00');
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $keptJob, $endedJob);
        $this->matcher->matchOpenProjects([$project]);
        self::assertSame([7_245_031, 7_245_032], $this->matchedJobIdsOf($ishtarLine));
        $endedJob->setStatus('cancelled');
        $this->givenMatchStatus($ishtarLine, 7_245_032, 'cancelled');
        $newJob = $this->job(7_245_033, 2, '2026-11-01 12:00:00');
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $newJob, $keptJob, $endedJob);

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_245_031, 7_245_033], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(5, $ishtarLine->getMatchedRuns());
    }

    #[Group('issue-1245')]
    public function testAKeptMatchStaysWhenTheRunsOfItsLineAreLoweredBelowTheJobAndNoNewJobIsAddedNorAFailureReported(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $project = $this->project($ishtarLine);
        $keptJob = $this->job(7_245_041, 5, '2026-11-02 08:00:00');
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $keptJob);
        $this->matcher->matchOpenProjects([$project]);
        $ishtarLine->setRuns(3);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $keptJob, $this->job(7_245_042, 2, '2026-11-03 08:00:00'));

        $failures = $this->matcher->matchOpenProjects([$project])[$project->getId()->toRfc4122()];

        self::assertSame([], $failures);
        self::assertSame([7_245_041], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(5, $ishtarLine->getMatchedRuns());
    }

    #[Group('issue-1245')]
    public function testAKeptJobIsNeverMatchedAgainToAnotherLineOfTheSameProject(): void
    {
        $lowered = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $other = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $project = $this->project($lowered, $other);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_245_051, 5, '2026-11-02 08:00:00'));
        $this->matcher->matchOpenProjects([$project]);
        $lowered->setRuns(3);

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_245_051], $this->matchedJobIdsOf($lowered));
        self::assertSame([], $this->matchedJobIdsOf($other));
    }

    #[Group('issue-1245')]
    public function testMatchingLeavesALineHoldingAManualMatchIntactWhileItReleasesTheOtherLinesWithoutOutput(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $ramLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', self::RAM_COMPONENT_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine, $ramLine);
        $this->matcher->linkJobToBomLine($this->job(7_245_061, 3, '2026-11-02 08:00:00', status: 'cancelled'), $ishtarLine);
        $ramJob = $this->job(7_245_062, self::RAM_COMPONENT_LINE_RUNS, '2026-11-02 09:00:00');
        $this->givenJobs(self::RAM_BLUEPRINT_TYPE_ID, $ramJob);
        $this->matcher->matchOpenProjects([$project]);
        self::assertSame([7_245_062], $this->matchedJobIdsOf($ramLine));
        $this->givenMatchStatus($ramLine, 7_245_062, 'cancelled');
        $ramJob->setStatus('cancelled');

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_245_061], $this->matchedJobIdsOf($ishtarLine));
        self::assertTrue($ishtarLine->getJobMatches()->first()->isManual());
        self::assertSame([], $this->matchedJobIdsOf($ramLine));
    }

    #[Group('issue-1246')]
    public function testAProjectWhoseMatchesWithoutOutputCannotBeReleasedIsLeftOutOfThePassAndLogged(): void
    {
        $lineOfBroken = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', self::RAM_COMPONENT_LINE_RUNS, 'manufacturing');
        $lineOfHealthy = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $broken = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfBroken);
        $healthy = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfHealthy);
        $staleJob = $this->job(7_246_091, 2, '2026-11-02 08:00:00');
        $this->givenJobs(self::RAM_BLUEPRINT_TYPE_ID, $staleJob);
        $this->matcher->matchOpenProjects([$broken]);
        self::assertSame([7_246_091], $this->matchedJobIdsOf($lineOfBroken));
        $staleJob->setStatus('cancelled');
        $this->givenMatchStatus($lineOfBroken, 7_246_091, 'cancelled');
        $this->unremovableMatch = $lineOfBroken->getJobMatches()->first();
        $this->givenJobs(self::RAM_BLUEPRINT_TYPE_ID, $staleJob, $this->job(7_246_092, 2, '2026-11-02 09:00:00'));
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_093, self::ISHTAR_FINAL_LINE_RUNS, '2026-11-02 10:00:00'));

        $failures = $this->matcher->matchOpenProjects([$broken, $healthy]);

        self::assertSame([7_246_093], $this->matchedJobIdsOf($lineOfHealthy));
        self::assertNotContains(7_246_092, $this->matchedJobIdsOf($lineOfBroken));
        self::assertSame([$broken->getId()->toRfc4122(), $healthy->getId()->toRfc4122()], array_keys($failures));
        $warnings = array_values(array_filter($this->logger->cleanLogs(), static fn (array $record): bool => 'warning' === $record[0]));
        self::assertCount(1, $warnings);
        self::assertSame($broken->getId()->toRfc4122(), $warnings[0][2]['projectId']);
    }

    #[Group('issue-1245')]
    public function testAJobTooLargeForTheRemainingRunsOfAProjectHoldingAKeptMatchIsNotContestedWithAnotherProject(): void
    {
        $lineOfFirst = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfSecond = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $first = $this->project($lineOfFirst);
        $second = $this->project($lineOfSecond);
        $keptJob = $this->job(7_245_071, 4, '2026-11-03 08:00:00');
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $keptJob);
        $this->matcher->matchOpenProjects([$first]);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_245_072, 7, '2026-11-02 08:00:00'), $keptJob);

        $failures = $this->matcher->matchOpenProjects([$first, $second]);

        self::assertSame([$first->getId()->toRfc4122() => [], $second->getId()->toRfc4122() => []], $failures);
        self::assertSame([7_245_071], $this->matchedJobIdsOf($lineOfFirst));
        self::assertSame([7_245_072], $this->matchedJobIdsOf($lineOfSecond));
    }

    #[Group('issue-1246')]
    public function testTwoProjectsOnTheSameBlueprintAreSeparatedOnlyByThePlannedStructureOfALine(): void
    {
        $structureConfig = $this->structureConfigAt(self::SOTIYO_STATION_ID);
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing')
            ->setPlannedStructureId($structureConfig->getId()->toRfc4122());
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_001, 5, '2026-11-02 08:00:00', stationId: self::SOTIYO_STATION_ID));

        $this->matcher->matchOpenProjects([$older, $newer]);

        self::assertSame([[], [7_246_001]], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame(['structure'], $lineOfNewer->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testAPlannedStructureWithoutKnownLocationGivesNoStructurePoint(): void
    {
        $structureConfig = $this->structureConfigAt(null);
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing')
            ->setPlannedStructureId($structureConfig->getId()->toRfc4122());
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_011, 5, '2026-11-02 08:00:00', stationId: self::SOTIYO_STATION_ID));

        $this->matcher->matchOpenProjects([$older, $newer]);

        self::assertSame([[7_246_011], []], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame([], $lineOfOlder->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testAPlannedStructureThatNoConfigurationMatchesGivesNoStructurePoint(): void
    {
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing')
            ->setPlannedStructureId('01970000-0000-7000-8000-0000000000a1');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_012, 5, '2026-11-02 08:00:00', stationId: self::SOTIYO_STATION_ID));

        $this->matcher->matchOpenProjects([$older, $newer]);

        self::assertSame([[7_246_012], []], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame([], $lineOfOlder->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testAPlannedStructureThatIsNotAUuidGivesNoStructurePointAndIsNeverLookedUp(): void
    {
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing')
            ->setPlannedStructureId('Sotiyo Alpha');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_013, 5, '2026-11-02 08:00:00', stationId: self::SOTIYO_STATION_ID));

        $this->matcher->matchOpenProjects([$older, $newer]);

        self::assertSame([[7_246_013], []], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
    }

    #[Group('issue-1246')]
    public function testAJobOfAParticipantOfOneProjectIsNotOfferedToAnotherProjectWithoutThatParticipant(): void
    {
        $participantCharacter = $this->character('Sana Vel');
        $lineOfOwnerOnly = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineWithParticipant = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $ownerOnly = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOwnerOnly);
        $withParticipant = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineWithParticipant);
        $this->givenParticipant($withParticipant, ParticipantStatus::Accepted, $participantCharacter);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_071, 5, '2026-11-02 08:00:00', $participantCharacter));

        $this->matcher->matchOpenProjects([$ownerOnly, $withParticipant]);

        self::assertSame([[], [7_246_071]], [$this->matchedJobIdsOf($lineOfOwnerOnly), $this->matchedJobIdsOf($lineWithParticipant)]);
    }

    #[Group('issue-1246')]
    public function testAJobStartedBetweenTheCreationsOfTwoProjectsIsLinkedOnlyToTheOlderOne(): void
    {
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_072, 5, '2026-11-01 10:00:00'));

        $this->matcher->matchOpenProjects([$newer, $older]);

        self::assertSame([[7_246_072], []], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
    }

    #[Group('issue-1246')]
    public function testAReservationOfAParticipantWithoutUserGivesNoIntentPoint(): void
    {
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $anonymousParticipant = $this->givenParticipantUser($newer, new User());
        $anonymousParticipant->anonymize();
        $this->reservationsByProjectId[$newer->getId()->toRfc4122()] = [new IndustryProjectLineReservation($lineOfNewer, $anonymousParticipant, 3)];
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_073, 5, '2026-11-02 08:00:00'));

        $this->matcher->matchOpenProjects([$older, $newer]);

        self::assertSame([[7_246_073], []], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame([], $lineOfOlder->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testTwoProjectsOnTheSameBlueprintAreSeparatedOnlyByTheProducerCharactersOfAProject(): void
    {
        $this->mainCharacter->setEveCharacterId(self::ARIA_EVE_CHARACTER_ID);
        $this->altCharacter->setEveCharacterId(self::ARIA_ALT_EVE_CHARACTER_ID);
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer)
            ->setProducerCharacterIds([self::ARIA_ALT_EVE_CHARACTER_ID]);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_021, 5, '2026-11-02 08:00:00', $this->altCharacter));

        $this->matcher->matchOpenProjects([$older, $newer]);

        self::assertSame([[], [7_246_021]], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame(['intent'], $lineOfNewer->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testAProducerListThatDoesNotHoldTheInstallerGivesNoIntentPointAndKeepsTheProjectEligible(): void
    {
        $this->mainCharacter->setEveCharacterId(self::ARIA_EVE_CHARACTER_ID);
        $this->altCharacter->setEveCharacterId(self::ARIA_ALT_EVE_CHARACTER_ID);
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $project = $this->project($ishtarLine)->setProducerCharacterIds([self::ARIA_ALT_EVE_CHARACTER_ID]);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_031, 5, '2026-11-02 08:00:00'));

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_246_031], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame([], $ishtarLine->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testTwoProjectsOnTheSameBlueprintAreSeparatedOnlyByALineReservedByTheUserOfTheInstaller(): void
    {
        $participantCharacter = $this->character('Sana Vel');
        $participantUser = (new User())->addCharacter($participantCharacter);
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $participantOfOlder = $this->givenParticipantUser($older, $participantUser);
        $participantOfNewer = $this->givenParticipantUser($newer, $participantUser);
        $this->reservationsByProjectId[$newer->getId()->toRfc4122()] = [new IndustryProjectLineReservation($lineOfNewer, $participantOfNewer, 3)];
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_041, 5, '2026-11-02 08:00:00', $participantCharacter));

        $this->matcher->matchOpenProjects([$older, $newer]);

        self::assertSame([[], [7_246_041]], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame(['intent'], $lineOfNewer->getJobMatches()->first()->getMatchSignals());
        self::assertNotSame($participantOfOlder, $participantOfNewer);
    }

    #[Group('issue-1246')]
    public function testALineReservedByAnotherUserThanTheInstallerGivesNoIntentPoint(): void
    {
        $otherCharacter = $this->character('Kel Dorn');
        $otherUser = (new User())->addCharacter($otherCharacter);
        $lineOfOlder = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfNewer = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $older = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfOlder);
        $newer = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfNewer);
        $participantOfNewer = $this->givenParticipantUser($newer, $otherUser);
        $this->reservationsByProjectId[$newer->getId()->toRfc4122()] = [new IndustryProjectLineReservation($lineOfNewer, $participantOfNewer, 3)];
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_246_051, 5, '2026-11-02 08:00:00'));

        $this->matcher->matchOpenProjects([$older, $newer]);

        self::assertSame([[7_246_051], []], [$this->matchedJobIdsOf($lineOfOlder), $this->matchedJobIdsOf($lineOfNewer)]);
        self::assertSame([], $lineOfOlder->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testAManualMatchHasNoSignalsWhileAnAutomaticMatchWithoutSignalHasAnEmptyList(): void
    {
        $manualLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $automaticLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 'component', 9, 'manufacturing');
        $project = $this->project($manualLine, $automaticLine);
        $manualMatch = $this->matcher->linkJobToBomLine($this->job(7_246_061, 3, '2026-11-02 08:00:00'), $manualLine);
        $this->givenJobs(self::RAM_BLUEPRINT_TYPE_ID, $this->job(7_246_062, 4, '2026-11-02 09:00:00'));

        $this->matcher->matchOpenProjects([$project]);

        self::assertSame([7_246_062], $this->matchedJobIdsOf($automaticLine));
        self::assertNull($manualMatch->getMatchSignals());
        self::assertTrue($manualMatch->isManual());
        self::assertSame([], $automaticLine->getJobMatches()->first()->getMatchSignals());
    }

    #[Group('issue-1246')]
    public function testTheOrderOfTheProjectsPassedToTheMatcherDoesNotChangeTheLinks(): void
    {
        $inGivenOrder = $this->linksOfTwoProjectsContestingTwoJobs(false);
        $inReverseOrder = $this->linksOfTwoProjectsContestingTwoJobs(true);

        self::assertSame([[7_246_072], [7_246_071]], $inGivenOrder);
        self::assertSame($inGivenOrder, $inReverseOrder);
    }

    #[Group('issue-1246')]
    public function testMatchOpenProjectsReturnsAnEntryPerProjectEvenWithoutAnyJobLine(): void
    {
        $tritaniumLine = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(12_000)->setIsJob(false);
        $materialsOnly = $this->project($tritaniumLine);
        $withoutRuns = $this->project($this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing')->setRuns(null));

        $failures = $this->matcher->matchOpenProjects([$materialsOnly, $withoutRuns]);

        self::assertSame([$materialsOnly->getId()->toRfc4122(), $withoutRuns->getId()->toRfc4122()], array_keys($failures));
        self::assertSame([], $failures[$materialsOnly->getId()->toRfc4122()]);
        self::assertCount(1, $failures[$withoutRuns->getId()->toRfc4122()]);
        self::assertSame([], $this->matcher->matchOpenProjects([]));
    }

    private function givenParticipantUser(IndustryProject $project, User $user): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())
            ->setUser($user)
            ->setRole(ParticipantRole::Member)
            ->setStatus(ParticipantStatus::Accepted);
        $project->addMember($participant);

        return $participant;
    }

    private function linksOfTwoProjectsContestingTwoJobs(bool $reversed): array
    {
        $this->projectsInPlay = [];
        $lineOfTen = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfSix = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 6, 'manufacturing');
        $ofTen = $this->projectCreatedAt(self::OLDER_PROJECT_CREATED_AT, $lineOfTen);
        $ofSix = $this->projectCreatedAt(self::NEWER_PROJECT_CREATED_AT, $lineOfSix);
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_246_071, 6, '2026-11-02 08:00:00'),
            $this->job(7_246_072, 4, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchOpenProjects($reversed ? [$ofSix, $ofTen] : [$ofTen, $ofSix]);

        return [$this->matchedJobIdsOf($lineOfTen), $this->matchedJobIdsOf($lineOfSix)];
    }

    private function givenMatchStatus(IndustryProjectBomLine $line, int $esiJobId, string $status): void
    {
        foreach ($line->getJobMatches() as $match) {
            if ($match->getEsiJobId() === $esiJobId) {
                $match->setStatus($status);
            }
        }
    }

    private function givenContainer(IndustryProject $project, int ...$itemIds): void
    {
        $this->scopesByProjectId[$project->getId()->toRfc4122()] = new ProjectContainerScope(null, array_values($itemIds));
    }

    private function givenParticipant(IndustryProject $project, ParticipantStatus $status, Character $character): IndustryProjectParticipant
    {
        $participant = (new IndustryProjectParticipant())
            ->setUser((new User())->addCharacter($character))
            ->setRole(ParticipantRole::Member)
            ->setStatus($status);
        $project->addMember($participant);

        return $participant;
    }

    private static function producer(int $blueprintTypeId, IndustryActivityType $activity, int $productTypeId): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId($activity->value)
            ->setProductTypeId($productTypeId)
            ->setQuantity(1);
    }

    private function character(string $name): Character
    {
        $character = (new Character())->setName($name);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::v4());

        return $character;
    }

    private function structureConfigAt(?int $locationId): IndustryStructureConfig
    {
        $structureConfig = (new IndustryStructureConfig())->setLocationId($locationId);
        $this->structureConfigsById[$structureConfig->getId()->toRfc4122()] = $structureConfig;

        return $structureConfig;
    }

    private function jobLine(int $typeId, string $typeName, string $jobGroup, int $runs, string $activityType): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($runs)
            ->setIsJob(true)
            ->setJobGroup($jobGroup)
            ->setActivityType($activityType)
            ->setRuns($runs);
    }

    private function project(IndustryProjectBomLine ...$lines): IndustryProject
    {
        return $this->projectCreatedAt(self::PROJECT_CREATED_AT, ...$lines);
    }

    private function projectCreatedAt(string $createdAt, IndustryProjectBomLine ...$lines): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::InProgress);
        $this->projectsInPlay[] = $project;
        (new \ReflectionProperty(IndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable($createdAt.' +00:00'));
        foreach ($lines as $line) {
            $project->addBomItem($line);
        }

        return $project;
    }

    private function job(
        int $jobId,
        int $runs,
        string $startDate,
        ?Character $installer = null,
        string $status = 'active',
        float $cost = 1_250_000.5,
        ?int $stationId = null,
        int $activityId = 1,
    ): CachedIndustryJob {
        return (new CachedIndustryJob())
            ->setJobId($jobId)
            ->setRuns($runs)
            ->setStatus($status)
            ->setCost($cost)
            ->setStationId($stationId)
            ->setActivityId($activityId)
            ->setBlueprintTypeId(self::ISHTAR_BLUEPRINT_TYPE_ID)
            ->setCharacter($installer ?? $this->mainCharacter)
            ->setStartDate(new \DateTimeImmutable($startDate.' +00:00'))
            ->setEndDate((new \DateTimeImmutable($startDate.' +00:00'))->modify('+2 days'));
    }

    private function jobIdsHeldByTheOtherProjectsInPlay(IndustryProject $project): array
    {
        $jobIds = [];
        foreach ($this->projectsInPlay as $other) {
            if ($other === $project) {
                continue;
            }
            foreach ($other->getBomItems() as $line) {
                foreach ($line->getJobMatches() as $match) {
                    $jobIds[] = $match->getEsiJobId();
                }
            }
        }

        return $jobIds;
    }

    private function givenJobs(int $blueprintTypeId, CachedIndustryJob ...$jobs): void
    {
        foreach ($jobs as $job) {
            $job->setBlueprintTypeId($blueprintTypeId);
        }
        $this->jobsByBlueprint[$blueprintTypeId] = array_values($jobs);
    }

    private function matchedJobIdsOf(IndustryProjectBomLine $line): array
    {
        return array_values(array_map(
            static fn (IndustryProjectJobMatch $match): int => $match->getEsiJobId(),
            $line->getJobMatches()->toArray(),
        ));
    }
}
