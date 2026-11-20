<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryProjectParticipant;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Enum\ParticipantRole;
use App\Enum\ParticipantStatus;
use App\Enum\ProjectStatus;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\Project\ProjectContainerScope;
use App\Service\Industry\Project\ProjectContainerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
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

    private CachedIndustryJobRepository $jobRepository;
    private IndustryProjectJobMatchRepository $jobMatchRepository;
    private IndustryActivityProductRepository $productRepository;
    private IndustryJobMatcher $matcher;

    private array $jobsByBlueprint = [];

    private array $jobsMatchedToProjects = [];

    private array $projectsInPlay = [];

    private array $jobQueries = [];

    private array $scopesByProjectId = [];

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
                    static fn (CachedIndustryJob $job): bool => $job->getStartDate() >= $startedSince,
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

        $this->matcher = new IndustryJobMatcher(
            $calculationService,
            $this->jobRepository,
            $this->productRepository,
            $this->jobMatchRepository,
            $this->createStub(EntityManagerInterface::class),
            new NullLogger(),
            $containerService,
        );

        $this->owner = new User();
        $this->mainCharacter = $this->character('Aria Solberg');
        $this->altCharacter = $this->character('Aria Alt');
        $this->owner->addCharacter($this->mainCharacter);
        $this->owner->addCharacter($this->altCharacter);
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

        $this->matcher->matchEsiJobs($project);

        self::assertSame([7_362_003, 7_362_002], $this->matchedJobIdsOf($ishtarLine));
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

        $this->matcher->matchEsiJobs($this->project($ishtarLine));

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

        $this->matcher->matchEsiJobs($project);

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

        $this->matcher->matchEsiJobs($project);

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

        $this->matcher->matchEsiJobs($this->project($ramLine));

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

        $this->matcher->matchEsiJobs($this->project($ramLine));

        self::assertSame([7_362_052], $this->matchedJobIdsOf($ramLine));
    }

    public function testReactionLineIsMatchedToTheJobsOfItsFormula(): void
    {
        $ferrogelLine = $this->jobLine(self::FERROGEL_TYPE_ID, 'Ferrogel', 'component', self::FERROGEL_REACTION_LINE_RUNS, 'reaction');
        $this->givenJobs(
            self::FERROGEL_FORMULA_TYPE_ID,
            $this->job(7_362_061, 6, '2026-11-02 08:00:00', activityId: self::ESI_REACTION_ACTIVITY_ID),
        );

        $this->matcher->matchEsiJobs($this->project($ferrogelLine));

        self::assertSame([7_362_061], $this->matchedJobIdsOf($ferrogelLine));
        self::assertSame(6, $ferrogelLine->getMatchedRuns());
    }

    public function testMaterialAndInventionLinesAreNotMatched(): void
    {
        $tritaniumLine = (new IndustryProjectBomLine())->setTypeId(self::TRITANIUM_TYPE_ID)->setTypeName('Tritanium')->setRequiredQuantity(12_000)->setIsJob(false);
        $inventionLine = $this->jobLine(self::ISHTAR_BLUEPRINT_TYPE_ID, 'Ishtar Blueprint', 'blueprint', 3, 'invention');

        $this->matcher->matchEsiJobs($this->project($tritaniumLine, $inventionLine));

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

        $this->matcher->matchEsiJobs($project);
        $this->matcher->matchEsiJobs($project);

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

        $this->matcher->matchEsiJobs($project);

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

        $this->matcher->matchEsiJobs($project);

        self::assertSame([$this->mainCharacter->getId(), $this->altCharacter->getId()], $this->jobQueries[0][1]);
    }

    #[Group('issue-318')]
    public function testTheOwnerEnrolledAsAParticipantIsReadOnce(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $project = $this->project($ishtarLine);
        $project->enrolOwner();
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_318_005, 5, '2026-11-02 08:00:00'));

        $this->matcher->matchEsiJobs($project);

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
        $this->matcher->matchEsiJobs($project);
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
        $this->matcher->matchEsiJobs($project);
        self::assertSame([7_123_012], $this->matchedJobIdsOf($ramLine));

        $this->matcher->releaseAutomaticMatches($project);

        self::assertSame([7_123_011], $this->matchedJobIdsOf($ishtarLine));
        self::assertTrue($ishtarLine->getJobMatches()->first()->isManual());
        self::assertSame([], $this->matchedJobIdsOf($ramLine));
    }

    #[Group('issue-1124')]
    public function testAJobFittingTheLinesOfTwoCandidateProjectsIsContested(): void
    {
        $lineOfFirst = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $lineOfSecond = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $first = $this->project($lineOfFirst);
        $second = $this->project($lineOfSecond);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_124_001, 5, '2026-11-02 08:00:00'));

        self::assertSame([7_124_001 => true], $this->matcher->contestedJobIds([$first, $second]));

        $this->matcher->matchEsiJobs($first, [7_124_001 => true]);
        $this->matcher->matchEsiJobs($second, [7_124_001 => true]);
        self::assertSame([[], []], [$this->matchedJobIdsOf($lineOfFirst), $this->matchedJobIdsOf($lineOfSecond)]);
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

        $contested = $this->matcher->contestedJobIds($ordered);
        foreach ($ordered as $project) {
            $this->matcher->matchEsiJobs($project, $contested);
        }

        self::assertSame([], $contested);
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

        $contested = $this->matcher->contestedJobIds([$sixRuns, $fourRuns]);
        $this->matcher->matchEsiJobs($fourRuns, $contested);
        $this->matcher->matchEsiJobs($sixRuns, $contested);

        self::assertSame([], $contested);
        self::assertSame([[7_124_021], [7_124_022]], [$this->matchedJobIdsOf($lineOfSix), $this->matchedJobIdsOf($lineOfFour)]);
    }

    #[Group('issue-1124')]
    public function testContestationIsRepeatedOnceAContestedJobIsSetAside(): void
    {
        $lineOfTen = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 10, 'manufacturing');
        $lineOfSix = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 6, 'manufacturing');
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_124_031, 6, '2026-11-02 08:00:00'),
            $this->job(7_124_032, 4, '2026-11-02 09:00:00'),
        );

        $contested = $this->matcher->contestedJobIds([$this->project($lineOfTen), $this->project($lineOfSix)]);

        self::assertEqualsCanonicalizing([7_124_031, 7_124_032], array_keys($contested));
    }

    #[Group('issue-1124')]
    public function testADraftProjectIsNotACandidateAndDoesNotContestTheJob(): void
    {
        $launched = $this->project($this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing'));
        $draft = $this->draftProject($this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing'));
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_124_041, 5, '2026-11-02 08:00:00'));

        self::assertSame([], $this->matcher->contestedJobIds([$launched, $draft]));
    }

    #[Group('issue-1124')]
    public function testALineHoldingAManualLinkIsNotACandidateForTheJob(): void
    {
        $manualLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $openLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $withManualLink = $this->project($manualLine);
        $open = $this->project($openLine);
        $this->matcher->linkJobToBomLine($this->job(7_124_051, 1, '2026-11-02 08:00:00'), $manualLine);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_124_052, 5, '2026-11-02 09:00:00'));

        $contested = $this->matcher->contestedJobIds([$withManualLink, $open]);
        $this->matcher->matchEsiJobs($open, $contested);

        self::assertSame([], $contested);
        self::assertSame([7_124_052], $this->matchedJobIdsOf($openLine));
    }

    #[Group('issue-1124')]
    public function testAContestedJobIsSkippedAndTheOtherJobsOfTheLineAreStillMatched(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_124_061, 5, '2026-11-02 08:00:00'),
            $this->job(7_124_062, 3, '2026-11-02 09:00:00'),
        );

        $this->matcher->matchEsiJobs($this->project($ishtarLine), [7_124_061 => true]);

        self::assertSame([7_124_062], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(3, $ishtarLine->getMatchedRuns());
    }

    #[Group('issue-1124')]
    public function testALineWithoutRunsDoesNotContestAJob(): void
    {
        $withoutRuns = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing')->setRuns(null);
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_124_071, 5, '2026-11-02 08:00:00'));

        self::assertSame([], $this->matcher->contestedJobIds([$this->project($withoutRuns), $this->project($ishtarLine)]));
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

        $this->matchAll($ordered);

        self::assertSame([[7_125_001], []], [$this->matchedJobIdsOf($lineOfOwner), $this->matchedJobIdsOf($lineOfOther)]);
    }

    #[Group('issue-1125')]
    public function testTheOutputLocationAloneNeverDecidesWhichProjectGetsTheJob(): void
    {
        $lineOfFirst = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $lineOfSecond = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $first = $this->project($lineOfFirst);
        $second = $this->project($lineOfSecond);
        $this->givenContainer($first, self::OWNER_CONTAINER_ID);
        $this->givenContainer($second, self::OTHER_CONTAINER_ID);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_011, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId(self::HANGAR_LOCATION_ID)
            ->setOutputLocationId(self::OTHER_CONTAINER_ID));

        $contested = $this->matchAll([$first, $second]);

        self::assertSame([7_125_011 => true], $contested);
        self::assertSame([[], []], [$this->matchedJobIdsOf($lineOfFirst), $this->matchedJobIdsOf($lineOfSecond)]);
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

        $this->matchAll([$small, $fitting]);

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

    #[Group('issue-1125')]
    public function testAJobTakingItsInputFromASharedContainerIsContestedBetweenTheProjectsUsingIt(): void
    {
        $lineOfFirst = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $lineOfSecond = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $first = $this->project($lineOfFirst);
        $second = $this->project($lineOfSecond);
        $this->givenContainer($first, self::OWNER_CONTAINER_ID);
        $this->givenContainer($second, self::OWNER_CONTAINER_ID);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_031, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId(self::OWNER_CONTAINER_ID));

        $contested = $this->matchAll([$first, $second]);

        self::assertSame([7_125_031 => true], $contested);
        self::assertSame([[], []], [$this->matchedJobIdsOf($lineOfFirst), $this->matchedJobIdsOf($lineOfSecond)]);
    }

    #[Group('issue-1125')]
    public function testTheContainerOfADraftProjectOrOfAProjectWithAnUnknownScopeClaimsNoJob(): void
    {
        $lineOfActive = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing');
        $active = $this->project($lineOfActive);
        $draft = $this->draftProject($this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing'));
        $unknown = $this->project($this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 2, 'manufacturing'));
        $this->givenContainer($draft, self::OTHER_CONTAINER_ID);
        $this->scopesByProjectId[$unknown->getId()->toRfc4122()] = new ProjectContainerScope('corporation_assets_not_synced', [self::OTHER_CONTAINER_ID]);
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_041, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId(self::OTHER_CONTAINER_ID));

        self::assertSame([], $this->matcher->containerOwnersByLocationId([$active, $draft, $unknown]));
        $this->matchAll([$active, $draft, $unknown]);

        self::assertSame([7_125_041], $this->matchedJobIdsOf($lineOfActive));
    }

    #[Group('issue-1125')]
    public function testContainerOwnersListTheProjectsOwningEachLocation(): void
    {
        $first = $this->project($this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing'));
        $second = $this->project($this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', 5, 'manufacturing'));
        $this->givenContainer($first, self::OWNER_CONTAINER_ID, self::OTHER_CONTAINER_ID);
        $this->givenContainer($second, self::OTHER_CONTAINER_ID);

        self::assertSame(
            [
                self::OWNER_CONTAINER_ID => [$first->getId()->toRfc4122()],
                self::OTHER_CONTAINER_ID => [$first->getId()->toRfc4122(), $second->getId()->toRfc4122()],
            ],
            $this->matcher->containerOwnersByLocationId([$first, $second]),
        );
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
        $this->givenJobs(self::ISHTAR_BLUEPRINT_TYPE_ID, $this->job(7_125_051, 5, '2026-11-02 08:00:00')
            ->setBlueprintLocationId(self::OWNER_CONTAINER_ID));

        $this->matchAll([$owner, $other]);

        self::assertSame([[], []], [$this->matchedJobIdsOf($lineOfOwner), $this->matchedJobIdsOf($lineOfOther)]);
    }

    /**
     * @param list<IndustryProject> $projects
     *
     * @return array<int, true>
     */
    private function matchAll(array $projects): array
    {
        $containerOwners = $this->matcher->containerOwnersByLocationId($projects);
        $contested = $this->matcher->contestedJobIds($projects, $containerOwners);
        foreach ($projects as $project) {
            $this->matcher->matchEsiJobs($project, $contested, $containerOwners);
        }

        return $contested;
    }

    private function givenContainer(IndustryProject $project, int ...$itemIds): void
    {
        $this->scopesByProjectId[$project->getId()->toRfc4122()] = new ProjectContainerScope(null, array_values($itemIds));
    }

    private function givenParticipant(IndustryProject $project, ParticipantStatus $status, Character $character): void
    {
        $project->addMember(
            (new IndustryProjectParticipant())
                ->setUser((new User())->addCharacter($character))
                ->setRole(ParticipantRole::Member)
                ->setStatus($status),
        );
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

    private function draftProject(IndustryProjectBomLine ...$lines): IndustryProject
    {
        return $this->project(...$lines)->setStatus(ProjectStatus::Draft);
    }

    private function project(IndustryProjectBomLine ...$lines): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner)->setStatus(ProjectStatus::InProgress);
        $this->projectsInPlay[] = $project;
        (new \ReflectionProperty(IndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable(self::PROJECT_CREATED_AT.' +00:00'));
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
