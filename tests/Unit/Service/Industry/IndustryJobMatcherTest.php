<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepJobMatch;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityMaterial;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\IndustryUserSettingsRepository;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\IndustryBonusService;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\IndustryStepCalculator;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Characterization tests: they freeze the CURRENT behavior of IndustryJobMatcher,
 * including behaviors suspected to be wrong (flagged in test names and comments).
 *
 * Boundaries mocked: job/product/structure repositories, EntityManager (+ DQL query),
 * IndustryCalculationService (facility name / structure bonus) and IndustryStepCalculator.
 * Filters done inside CachedIndustryJobRepository::findManufacturingJobsByBlueprint()
 * (activity ids 1/9/11, characters, startDate) are NOT exercised here: the mock returns
 * whatever jobs the test decides. Only the arguments the matcher passes are asserted.
 */
#[CoversClass(IndustryJobMatcher::class)]
#[AllowMockObjectsWithoutExpectations]
class IndustryJobMatcherTest extends TestCase
{
    private const BLUEPRINT_TYPE_ID = 1001;
    private const PRODUCT_TYPE_ID = 2001;
    private const OTHER_BLUEPRINT_TYPE_ID = 1002;
    private const COMPONENT_BLUEPRINT_TYPE_ID = 1003;
    private const COMPONENT_TYPE_ID = 3001;
    private const OUTPUT_PER_RUN = 100;
    private const NPC_STATION_ID = 60003760;
    private const STRUCTURE_LOCATION_ID = 1_035_466_617_946;
    private const OTHER_STRUCTURE_LOCATION_ID = 1_040_000_000_001;

    private IndustryStepCalculator&MockObject $stepCalculator;
    private IndustryCalculationService&MockObject $calculationService;
    private CachedIndustryJobRepository&MockObject $jobRepository;
    private IndustryActivityProductRepository&MockObject $productRepository;
    private IndustryStructureConfigRepository&MockObject $structureConfigRepository;
    private EntityManagerInterface&MockObject $entityManager;
    private Query&MockObject $otherProjectsMatchesQuery;
    private IndustryJobMatcher $matcher;

    /** @var array<int, list<CachedIndustryJob>> jobs returned by the repository, keyed by blueprint type id */
    private array $jobsByBlueprint = [];

    /** @var list<array{esiJobId: int}> rows returned by the "matches of other projects" DQL query */
    private array $otherProjectsMatchedJobRows = [];

    private User $user;
    private Character $mainCharacter;
    private Character $altCharacter;

    protected function setUp(): void
    {
        $this->stepCalculator = $this->createMock(IndustryStepCalculator::class);
        $this->calculationService = $this->createMock(IndustryCalculationService::class);
        $this->jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $this->productRepository = $this->createMock(IndustryActivityProductRepository::class);
        $this->structureConfigRepository = $this->createMock(IndustryStructureConfigRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->otherProjectsMatchesQuery = $this->createMock(Query::class);
        $this->otherProjectsMatchesQuery->method('setParameter')->willReturnSelf();
        $this->otherProjectsMatchesQuery->method('getScalarResult')
            ->willReturnCallback(fn (): array => $this->otherProjectsMatchedJobRows);
        $this->entityManager->method('createQuery')->willReturn($this->otherProjectsMatchesQuery);

        $this->jobRepository->method('findManufacturingJobsByBlueprint')
            ->willReturnCallback(fn (int $blueprintTypeId): array => $this->jobsByBlueprint[$blueprintTypeId] ?? []);

        $this->calculationService->method('resolveFacilityName')
            ->willReturnCallback(fn (int $stationId): string => 'Facility ' . $stationId);

        $this->user = new User();
        $this->mainCharacter = $this->createCharacter('Main Industrialist');
        $this->altCharacter = $this->createCharacter('Alt Industrialist');
        $this->user->addCharacter($this->mainCharacter);
        $this->user->addCharacter($this->altCharacter);

        $this->matcher = new IndustryJobMatcher(
            $this->stepCalculator,
            $this->calculationService,
            $this->jobRepository,
            $this->productRepository,
            $this->structureConfigRepository,
            $this->entityManager,
        );
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function createCharacter(string $name): Character
    {
        $character = new Character();
        $character->setName($name);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($character, Uuid::v4());

        return $character;
    }

    private function createStep(
        int $runs,
        int $quantity,
        int $blueprintTypeId = self::BLUEPRINT_TYPE_ID,
        string $activityType = 'manufacturing',
    ): IndustryProjectStep {
        $step = new IndustryProjectStep();
        $step->setBlueprintTypeId($blueprintTypeId);
        $step->setProductTypeId(self::PRODUCT_TYPE_ID);
        $step->setActivityType($activityType);
        $step->setRuns($runs);
        $step->setQuantity($quantity);
        $step->setDepth(0);

        return $step;
    }

    private function createProject(IndustryProjectStep ...$steps): IndustryProject
    {
        $project = new IndustryProject();
        $project->setUser($this->user);
        $project->setProductTypeId(self::PRODUCT_TYPE_ID);
        $project->setRuns(10);
        $project->setJobsStartDate(new \DateTimeImmutable('2026-09-01 00:00:00'));
        foreach ($steps as $step) {
            $project->addStep($step);
        }

        return $project;
    }

    private function createJob(
        int $jobId,
        int $runs,
        string $status = 'active',
        ?int $stationId = null,
        ?Character $character = null,
    ): CachedIndustryJob {
        $job = new CachedIndustryJob();
        $job->setJobId($jobId);
        $job->setRuns($runs);
        $job->setStatus($status);
        $job->setStationId($stationId);
        $job->setCharacter($character ?? $this->mainCharacter);
        $job->setActivityId(1);
        $job->setBlueprintTypeId(self::BLUEPRINT_TYPE_ID);
        $job->setProductTypeId(self::PRODUCT_TYPE_ID);
        $job->setCost(1_250_000.5);
        $job->setStartDate(new \DateTimeImmutable('2026-09-10 12:00:00'));
        $job->setEndDate(new \DateTimeImmutable('2026-09-12 12:00:00'));

        return $job;
    }

    private function givenJobsForBlueprint(int $blueprintTypeId, CachedIndustryJob ...$jobs): void
    {
        $this->jobsByBlueprint[$blueprintTypeId] = $jobs;
    }

    private function givenOutputPerRun(int $outputPerRun): void
    {
        $product = new IndustryActivityProduct();
        $product->setQuantity($outputPerRun);
        $this->productRepository->method('findOneBy')->willReturn($product);
    }

    private function createStructureConfig(string $name, int $locationId): IndustryStructureConfig
    {
        $config = new IndustryStructureConfig();
        $config->setName($name);
        $config->setLocationId($locationId);

        return $config;
    }

    private function createExistingMatch(int $esiJobId, int $runs): IndustryStepJobMatch
    {
        $match = new IndustryStepJobMatch();
        $match->setEsiJobId($esiJobId);
        $match->setRuns($runs);

        return $match;
    }

    /**
     * @return list<int>
     */
    private function matchedJobIds(IndustryProjectStep $step): array
    {
        return array_values(array_map(
            static fn (IndustryStepJobMatch $match): int => $match->getEsiJobId(),
            $step->getJobMatches()->toArray(),
        ));
    }

    // ===========================================
    // Guard: user without characters
    // ===========================================

    public function testUserWithoutCharactersLeavesProjectUntouched(): void
    {
        $this->user = new User();
        $step = $this->createStep(runs: 10, quantity: 10);
        $existingMatch = $this->createExistingMatch(esiJobId: 4242, runs: 10);
        $step->addJobMatch($existingMatch);
        $project = $this->createProject($step);

        $this->jobRepository->expects($this->never())->method('findManufacturingJobsByBlueprint');
        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');
        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([4242], $this->matchedJobIds($step));
    }

    // ===========================================
    // Repository query criteria (passed by the matcher)
    // ===========================================

    public function testQueriesJobsByStepBlueprintUserCharactersWithoutRunsFilterSinceProjectJobsStartDate(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $project->setJobsStartDate(new \DateTimeImmutable('2026-09-15 08:30:00'));

        $this->jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $this->jobRepository->expects($this->once())
            ->method('findManufacturingJobsByBlueprint')
            ->with(
                self::BLUEPRINT_TYPE_ID,
                [$this->mainCharacter->getId(), $this->altCharacter->getId()],
                null,
                new \DateTimeImmutable('2026-09-15 08:30:00'),
            )
            ->willReturn([]);
        $matcher = new IndustryJobMatcher(
            $this->stepCalculator,
            $this->calculationService,
            $this->jobRepository,
            $this->productRepository,
            $this->structureConfigRepository,
            $this->entityManager,
        );

        $matcher->matchEsiJobs($project);
    }

    public function testQueriesJobsSinceProjectCreationWhenJobsStartDateIsNotSet(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $project->setJobsStartDate(null);

        $this->jobRepository = $this->createMock(CachedIndustryJobRepository::class);
        $this->jobRepository->expects($this->once())
            ->method('findManufacturingJobsByBlueprint')
            ->with(self::BLUEPRINT_TYPE_ID, $this->anything(), null, $project->getCreatedAt())
            ->willReturn([]);
        $matcher = new IndustryJobMatcher(
            $this->stepCalculator,
            $this->calculationService,
            $this->jobRepository,
            $this->productRepository,
            $this->structureConfigRepository,
            $this->entityManager,
        );

        $matcher->matchEsiJobs($project);
    }

    public function testOtherProjectsMatchesQueryExcludesCurrentProject(): void
    {
        $project = $this->createProject($this->createStep(runs: 10, quantity: 10));

        $query =$this->createMock(Query::class);
        $query->expects($this->once())->method('setParameter')->with('project', $project)->willReturnSelf();
        $query->method('getScalarResult')->willReturn([]);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->expects($this->once())
            ->method('createQuery')
            ->with($this->stringContains('WHERE s.project != :project'))
            ->willReturn($query);
        $matcher = new IndustryJobMatcher(
            $this->stepCalculator,
            $this->calculationService,
            $this->jobRepository,
            $this->productRepository,
            $this->structureConfigRepository,
            $this->entityManager,
        );

        $matcher->matchEsiJobs($project);
    }

    // ===========================================
    // Basic matching and copied fields
    // ===========================================

    public function testJobWithSameRunsAsStepIsMatchedWithoutChangingStepRunsOrQuantity(): void
    {
        $step = $this->createStep(runs: 10, quantity: 1000);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));

        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
        $this->assertSame(1000, $step->getQuantity());
    }

    public function testJobMatchCopiesEsiJobFields(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $job = $this->createJob(
            jobId: 5001,
            runs: 10,
            status: 'delivered',
            stationId: self::NPC_STATION_ID,
            character: $this->altCharacter,
        );
        $job->setCost(3_456_789.25);
        $job->setEndDate(new \DateTimeImmutable('2026-09-20 18:45:00'));
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $job);

        $this->matcher->matchEsiJobs($project);

        $this->assertCount(1, $step->getJobMatches());
        $match = $step->getJobMatches()->first();
        $this->assertSame($step, $match->getStep());
        $this->assertSame(5001, $match->getEsiJobId());
        $this->assertSame(10, $match->getRuns());
        $this->assertSame(3_456_789.25, $match->getCost());
        $this->assertSame('delivered', $match->getStatus());
        $this->assertEquals(new \DateTimeImmutable('2026-09-20 18:45:00'), $match->getEndDate());
        $this->assertSame('Alt Industrialist', $match->getCharacterName());
        $this->assertSame(self::NPC_STATION_ID, $match->getFacilityId());
        $this->assertSame('Facility ' . self::NPC_STATION_ID, $match->getFacilityName());
        $this->assertNull($match->getPlannedStructureName());
        $this->assertNull($match->getPlannedMaterialBonus());
    }

    public function testJobWithoutStationLeavesFacilityEmptyAndResolvesNoName(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10, stationId: null));

        $this->calculationService->expects($this->never())->method('resolveFacilityName');
        $this->structureConfigRepository->expects($this->never())->method('findByUserAndLocationId');

        $this->matcher->matchEsiJobs($project);

        $match = $step->getJobMatches()->first();
        $this->assertSame(5001, $match->getEsiJobId());
        $this->assertNull($match->getFacilityId());
        $this->assertNull($match->getFacilityName());
    }

    // ===========================================
    // First come, first served across projects and steps
    // ===========================================

    public function testJobAlreadyMatchedToAnotherProjectIsNotMatchedAgain(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->otherProjectsMatchedJobRows = [['esiJobId' => 5001]];
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 10),
            $this->createJob(jobId: 5002, runs: 10),
        );

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5002], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
    }

    public function testJobOnlyMatchedToAnotherProjectLeavesStepWithoutMatch(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->otherProjectsMatchedJobRows = [['esiJobId' => 5001]];
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));

        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
    }

    public function testSplitStepsSharingBlueprintEachGetTheirOwnJob(): void
    {
        $firstSplit = $this->createStep(runs: 5, quantity: 5);
        $firstSplit->setSplitGroupId('split-group-1');
        $firstSplit->setSplitIndex(0);
        $firstSplit->setTotalGroupRuns(10);
        $secondSplit = $this->createStep(runs: 5, quantity: 5);
        $secondSplit->setSplitGroupId('split-group-1');
        $secondSplit->setSplitIndex(1);
        $secondSplit->setTotalGroupRuns(10);
        $project = $this->createProject($firstSplit, $secondSplit);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 5),
            $this->createJob(jobId: 5002, runs: 5),
        );

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($firstSplit));
        $this->assertSame([5002], $this->matchedJobIds($secondSplit));
        $this->assertSame(5, $firstSplit->getRuns());
        $this->assertSame(5, $secondSplit->getRuns());
    }

    public function testSplitStepAdaptsOnlyItsOwnRunsAndLeavesTotalGroupRunsAndSiblingUnchanged(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        // The first split takes both jobs (3 + oversized fallback 4 = 7 runs), the sibling gets none,
        // and totalGroupRuns still says 10.
        $this->givenOutputPerRun(1);
        $firstSplit = $this->createStep(runs: 5, quantity: 5);
        $firstSplit->setSplitGroupId('split-group-1');
        $firstSplit->setTotalGroupRuns(10);
        $secondSplit = $this->createStep(runs: 5, quantity: 5);
        $secondSplit->setSplitGroupId('split-group-1');
        $secondSplit->setSplitIndex(1);
        $secondSplit->setTotalGroupRuns(10);
        $project = $this->createProject($firstSplit, $secondSplit);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 3),
            $this->createJob(jobId: 5002, runs: 4),
        );

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001, 5002], $this->matchedJobIds($firstSplit));
        $this->assertSame([], $this->matchedJobIds($secondSplit));
        $this->assertSame(7, $firstSplit->getRuns());
        $this->assertSame(7, $firstSplit->getQuantity());
        $this->assertSame(5, $secondSplit->getRuns());
        $this->assertSame(10, $firstSplit->getTotalGroupRuns());
        $this->assertSame(10, $secondSplit->getTotalGroupRuns());
    }

    // ===========================================
    // Greedy assignment of runs
    // ===========================================

    public function testSeveralJobsCoveringStepRunsAreAllMatched(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 4),
            $this->createJob(jobId: 5002, runs: 6),
        );

        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001, 5002], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
    }

    public function testJobsAfterStepRunsAreCoveredAreNotMatched(): void
    {
        $step = $this->createStep(runs: 5, quantity: 5);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 5),
            $this->createJob(jobId: 5002, runs: 3),
        );

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
        $this->assertSame(5, $step->getRuns());
    }

    public function testJobWithMoreRunsThanRemainingIsSkippedWhenLaterJobsFit(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 4),
            $this->createJob(jobId: 5002, runs: 8),
            $this->createJob(jobId: 5003, runs: 6),
        );

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001, 5003], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
    }

    public function testOversizedJobIsMatchedAsFallbackAndStepRunsGrowToItsRuns(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        $this->givenOutputPerRun(self::OUTPUT_PER_RUN);
        $step = $this->createStep(runs: 10, quantity: 1000);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 15));

        $this->stepCalculator->expects($this->once())->method('recalculateStepQuantities')->with($project);

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
        $this->assertSame(15, $step->getRuns());
        $this->assertSame(1500, $step->getQuantity());
    }

    public function testOnlyFirstOversizedJobIsMatchedAsFallback(): void
    {
        $this->givenOutputPerRun(1);
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 15),
            $this->createJob(jobId: 5002, runs: 20),
        );

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
        $this->assertSame(15, $step->getRuns());
    }

    public function testFallbackAddsOversizedJobOnTopOfPartialMatchSoStepRunsExceedPlannedRuns(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        // 10 runs planned, jobs of 8 and 5 runs: 8 fits, 5 > 2 remaining is then taken as fallback.
        // Step ends with 13 runs although only 2 were missing.
        $this->givenOutputPerRun(self::OUTPUT_PER_RUN);
        $step = $this->createStep(runs: 10, quantity: 1000);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 8),
            $this->createJob(jobId: 5002, runs: 5),
        );

        $this->stepCalculator->expects($this->once())->method('recalculateStepQuantities')->with($project);

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001, 5002], $this->matchedJobIds($step));
        $this->assertSame(13, $step->getRuns());
        $this->assertSame(1300, $step->getQuantity());
    }

    // ===========================================
    // Step runs adaptation (issue #3)
    // ===========================================

    public function testStepRunsShrinkToMatchedRunsWhenJobsCoverFewerRunsThanPlanned(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        // A 10-run step with a single 5-run job becomes a 5-run step (quantity recomputed),
        // then the whole project quantities are recalculated.
        $step = $this->createStep(runs: 10, quantity: 1000);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 5));

        $product = new IndustryActivityProduct();
        $product->setQuantity(self::OUTPUT_PER_RUN);
        $this->productRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['typeId' => self::BLUEPRINT_TYPE_ID, 'activityId' => 1])
            ->willReturn($product);
        $this->stepCalculator->expects($this->once())->method('recalculateStepQuantities')->with($project);

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
        $this->assertSame(5, $step->getRuns());
        $this->assertSame(500, $step->getQuantity());
    }

    public function testReactionStepAdaptationUsesReactionOutputPerRun(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        $step = $this->createStep(runs: 10, quantity: 2000, activityType: 'reaction');
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 4));

        $product = new IndustryActivityProduct();
        $product->setQuantity(200);
        $this->productRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['typeId' => self::BLUEPRINT_TYPE_ID, 'activityId' => 11])
            ->willReturn($product);
        $this->stepCalculator->expects($this->once())->method('recalculateStepQuantities')->with($project);

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
        $this->assertSame(4, $step->getRuns());
        $this->assertSame(800, $step->getQuantity());
    }

    public function testStepAdaptationAssumesOneUnitPerRunWhenSdeProductIsMissing(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        $step = $this->createStep(runs: 10, quantity: 1000);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 5));
        $this->productRepository->expects($this->once())
            ->method('findOneBy')
            ->with(['typeId' => self::BLUEPRINT_TYPE_ID, 'activityId' => 1])
            ->willReturn(null);
        $this->stepCalculator->expects($this->once())->method('recalculateStepQuantities')->with($project);

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
        $this->assertSame(5, $step->getRuns());
        $this->assertSame(5, $step->getQuantity());
    }

    // ===========================================
    // Job status (issue #4)
    // ===========================================

    public function testCancelledJobIsIgnoredAndActiveJobIsMatched(): void
    {
        // A cancelled job never produced anything: it must not cover the step runs.
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 10, status: 'cancelled'),
            $this->createJob(jobId: 5002, runs: 10, status: 'active'),
        );

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5002], $this->matchedJobIds($step));
        $this->assertSame('active', $step->getJobMatches()->first()->getStatus());
        $this->assertSame(10, $step->getRuns());
    }

    public function testCancelledOversizedJobIsNotUsedAsFallback(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 20, status: 'cancelled'),
        );

        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
        $this->assertSame(10, $step->getQuantity());
    }

    public function testCancelledJobInAnotherStructureDoesNotSwitchStepStructure(): void
    {
        // A cancelled job never ran: its facility must not drive the step structure auto-correction.
        $plannedStructure = $this->createStructureConfig('Planned Raitaru', self::STRUCTURE_LOCATION_ID);
        $actualStructure = $this->createStructureConfig('Actual Azbel', self::OTHER_STRUCTURE_LOCATION_ID);
        $step = $this->createStep(runs: 10, quantity: 10);
        $step->setStructureConfig($plannedStructure);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 10, status: 'cancelled', stationId: self::OTHER_STRUCTURE_LOCATION_ID),
            $this->createJob(jobId: 5002, runs: 10, status: 'active', stationId: self::STRUCTURE_LOCATION_ID),
        );
        $this->structureConfigRepository->method('findByUserAndLocationId')->willReturn($actualStructure);
        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn(['materialBonus' => ['total' => 2.4]]);

        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5002], $this->matchedJobIds($step));
        $this->assertSame($plannedStructure, $step->getStructureConfig());
        $this->assertNull($step->getJobMatches()->first()->getPlannedStructureName());
    }

    // ===========================================
    // Skipped steps
    // ===========================================

    public function testPurchasedStepIsNotMatchedAndLosesItsPreviousMatches(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $step->setPurchased(true);
        $step->addJobMatch($this->createExistingMatch(esiJobId: 4242, runs: 10));
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));

        $this->jobRepository->expects($this->never())->method('findManufacturingJobsByBlueprint');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
    }

    public function testInStockStepIsStillMatched(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $step->setInStockQuantity(10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));

        $this->matcher->matchEsiJobs($project);

        $this->assertTrue($step->isInStock());
        $this->assertSame([5001], $this->matchedJobIds($step));
    }

    public function testCopyStepIsNotMatched(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10, activityType: 'copy');
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));

        $this->jobRepository->expects($this->never())->method('findManufacturingJobsByBlueprint');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([], $this->matchedJobIds($step));
    }

    public function testStepWithJobMatchModeNoneIsNotMatched(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $step->setJobMatchMode('none');
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));

        $this->jobRepository->expects($this->never())->method('findManufacturingJobsByBlueprint');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([], $this->matchedJobIds($step));
    }

    public function testStepWithJobMatchModeManualKeepsItsManualLinkAndGetsNoAutomaticMatch(): void
    {
        // The job linked by hand (LinkJobProcessor) survives a re-match; no automatic job is added.
        $step = $this->createStep(runs: 10, quantity: 10);
        $step->setJobMatchMode('manual');
        $manualLink = $this->createExistingMatch(esiJobId: 7777, runs: 10);
        $step->addJobMatch($manualLink);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));

        $this->entityManager->expects($this->never())->method('remove');
        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([7777], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
        $this->assertSame(10, $step->getQuantity());
    }

    public function testJobManuallyLinkedToManualStepIsNotAutoMatchedToAnotherStepOfTheProject(): void
    {
        // Split steps share a blueprint: the job kept on the manual step must not be matched twice,
        // even when the auto step comes first in the project.
        $manualStep = $this->createStep(runs: 10, quantity: 10);
        $manualStep->setJobMatchMode('manual');
        $manualStep->addJobMatch($this->createExistingMatch(esiJobId: 5001, runs: 10));
        $autoStep = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($autoStep, $manualStep);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 10),
            $this->createJob(jobId: 5002, runs: 10),
        );

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($manualStep));
        $this->assertSame([5002], $this->matchedJobIds($autoStep));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function skippedStepProvider(): array
    {
        return [
            'purchased step' => ['purchased'],
            'copy step' => ['copy'],
            'jobMatchMode none' => ['none'],
            'jobMatchMode manual' => ['manual'],
        ];
    }

    #[DataProvider('skippedStepProvider')]
    public function testSkippedStepDoesNotStopMatchingOfFollowingSteps(string $skipReason): void
    {
        $skippedStep = $this->createStep(
            runs: 10,
            quantity: 10,
            activityType: $skipReason === 'copy' ? 'copy' : 'manufacturing',
        );
        if ($skipReason === 'purchased') {
            $skippedStep->setPurchased(true);
        }
        if ($skipReason === 'none' || $skipReason === 'manual') {
            $skippedStep->setJobMatchMode($skipReason);
        }
        $followingStep = $this->createStep(runs: 3, quantity: 3, blueprintTypeId: self::OTHER_BLUEPRINT_TYPE_ID);
        $project = $this->createProject($skippedStep, $followingStep);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));
        $this->givenJobsForBlueprint(self::OTHER_BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5002, runs: 3));

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([], $this->matchedJobIds($skippedStep));
        $this->assertSame([5002], $this->matchedJobIds($followingStep));
        $this->assertSame(3, $followingStep->getRuns());
    }

    public function testStepWithoutJobsGetsNoMatchAndKeepsItsRuns(): void
    {
        $step = $this->createStep(runs: 10, quantity: 1000);
        $otherStep = $this->createStep(runs: 3, quantity: 3, blueprintTypeId: self::OTHER_BLUEPRINT_TYPE_ID);
        $project = $this->createProject($step, $otherStep);
        $this->givenJobsForBlueprint(self::OTHER_BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 3));

        $this->entityManager->expects($this->exactly(2))->method('flush');
        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
        $this->assertSame(1000, $step->getQuantity());
        $this->assertSame([5001], $this->matchedJobIds($otherStep));
    }

    public function testZeroRunStepGetsNoMatch(): void
    {
        $step = $this->createStep(runs: 0, quantity: 0);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 5));

        $this->productRepository->expects($this->never())->method('findOneBy');
        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([], $this->matchedJobIds($step));
        $this->assertSame(0, $step->getRuns());
        $this->assertSame(0, $step->getQuantity());
    }

    // ===========================================
    // Re-run: previous matches and idempotence
    // ===========================================

    public function testPreviousMatchesAreRemovedBeforeMatchingAgain(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $oldMatch = $this->createExistingMatch(esiJobId: 4242, runs: 10);
        $step->addJobMatch($oldMatch);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 10));

        $this->entityManager->expects($this->once())->method('remove')->with($oldMatch);

        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
    }

    public function testMatchingTwiceDoesNotDuplicateMatches(): void
    {
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 4),
            $this->createJob(jobId: 5002, runs: 6),
        );

        // Second run removes the 2 matches created by the first run.
        $this->entityManager->expects($this->exactly(2))->method('remove');
        $this->entityManager->expects($this->exactly(4))->method('flush');

        $this->matcher->matchEsiJobs($project);
        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001, 5002], $this->matchedJobIds($step));
        $this->assertSame(10, $step->getRuns());
    }

    public function testMatchingTwiceKeepsStepRunsShrunkByFirstRun(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        // After the first run, the 10-run step became a 5-run step; the second run sees
        // 5 planned runs covered by the 5-run job and never restores the original 10.
        $this->givenOutputPerRun(self::OUTPUT_PER_RUN);
        $step = $this->createStep(runs: 10, quantity: 1000);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(self::BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 5));

        $this->stepCalculator->expects($this->once())->method('recalculateStepQuantities')->with($project);

        $this->matcher->matchEsiJobs($project);
        $this->matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($step));
        $this->assertSame(5, $step->getRuns());
        $this->assertSame(500, $step->getQuantity());
    }

    // ===========================================
    // Facility auto-correction of the step structure
    // ===========================================

    public function testJobInAnotherConfiguredStructureSwitchesStepStructureAndRecordsPlannedOne(): void
    {
        $plannedStructure = $this->createStructureConfig('Planned Raitaru', self::STRUCTURE_LOCATION_ID);
        $actualStructure = $this->createStructureConfig('Actual Azbel', self::OTHER_STRUCTURE_LOCATION_ID);
        $step = $this->createStep(runs: 10, quantity: 10);
        $step->setStructureConfig($plannedStructure);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 10, stationId: self::OTHER_STRUCTURE_LOCATION_ID),
        );

        $this->structureConfigRepository->expects($this->once())
            ->method('findByUserAndLocationId')
            ->with($this->user, self::OTHER_STRUCTURE_LOCATION_ID)
            ->willReturn($actualStructure);
        // Bonus is read while the step still has its planned structure.
        $this->calculationService->expects($this->once())
            ->method('getStructureBonusForStep')
            ->willReturnCallback(fn (IndustryProjectStep $s): array => [
                'materialBonus' => ['total' => $s->getStructureConfig() === $plannedStructure ? 2.4 : 99.0],
            ]);
        // Runs are unchanged: the recalculation is triggered by the structure switch alone.
        $this->stepCalculator->expects($this->once())->method('recalculateStepQuantities')->with($project);

        $this->matcher->matchEsiJobs($project);

        $match = $step->getJobMatches()->first();
        $this->assertSame($actualStructure, $step->getStructureConfig());
        $this->assertSame('Planned Raitaru', $match->getPlannedStructureName());
        $this->assertSame(2.4, $match->getPlannedMaterialBonus());
        $this->assertSame(self::OTHER_STRUCTURE_LOCATION_ID, $match->getFacilityId());
        $this->assertSame('Facility ' . self::OTHER_STRUCTURE_LOCATION_ID, $match->getFacilityName());
        $this->assertSame(10, $step->getRuns());
    }

    public function testJobInConfiguredStructureForStepWithoutStructureRecordsAucuneStructure(): void
    {
        $actualStructure = $this->createStructureConfig('Actual Azbel', self::OTHER_STRUCTURE_LOCATION_ID);
        $step = $this->createStep(runs: 10, quantity: 10);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 10, stationId: self::OTHER_STRUCTURE_LOCATION_ID),
        );
        $this->structureConfigRepository->method('findByUserAndLocationId')->willReturn($actualStructure);
        $this->calculationService->method('getStructureBonusForStep')
            ->willReturn(['materialBonus' => ['total' => 0.0]]);

        $this->matcher->matchEsiJobs($project);

        $match = $step->getJobMatches()->first();
        $this->assertSame($actualStructure, $step->getStructureConfig());
        $this->assertSame('Aucune structure', $match->getPlannedStructureName());
        $this->assertSame(0.0, $match->getPlannedMaterialBonus());
    }

    public function testJobInStepCurrentStructureDoesNotLookUpOtherStructure(): void
    {
        $plannedStructure = $this->createStructureConfig('Planned Raitaru', self::STRUCTURE_LOCATION_ID);
        $step = $this->createStep(runs: 10, quantity: 10);
        $step->setStructureConfig($plannedStructure);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 10, stationId: self::STRUCTURE_LOCATION_ID),
        );

        $this->structureConfigRepository->expects($this->never())->method('findByUserAndLocationId');
        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $match = $step->getJobMatches()->first();
        $this->assertSame($plannedStructure, $step->getStructureConfig());
        $this->assertNull($match->getPlannedStructureName());
        $this->assertSame(self::STRUCTURE_LOCATION_ID, $match->getFacilityId());
    }

    public function testJobInUnconfiguredStructureKeepsStepStructure(): void
    {
        $plannedStructure = $this->createStructureConfig('Planned Raitaru', self::STRUCTURE_LOCATION_ID);
        $step = $this->createStep(runs: 10, quantity: 10);
        $step->setStructureConfig($plannedStructure);
        $project = $this->createProject($step);
        $this->givenJobsForBlueprint(
            self::BLUEPRINT_TYPE_ID,
            $this->createJob(jobId: 5001, runs: 10, stationId: self::NPC_STATION_ID),
        );
        $this->structureConfigRepository->method('findByUserAndLocationId')->willReturn(null);

        $this->calculationService->expects($this->never())->method('getStructureBonusForStep');
        $this->stepCalculator->expects($this->never())->method('recalculateStepQuantities');

        $this->matcher->matchEsiJobs($project);

        $match = $step->getJobMatches()->first();
        $this->assertSame($plannedStructure, $step->getStructureConfig());
        $this->assertNull($match->getPlannedStructureName());
        $this->assertNull($match->getPlannedMaterialBonus());
        $this->assertSame(self::NPC_STATION_ID, $match->getFacilityId());
        $this->assertSame('Facility ' . self::NPC_STATION_ID, $match->getFacilityName());
    }

    // ===========================================
    // With the real IndustryStepCalculator (issue #3)
    // ===========================================

    /**
     * Builds a matcher wired to the REAL IndustryStepCalculator (and the real
     * IndustryCalculationService behind it). Only the SDE repositories and the
     * other boundaries are stubbed.
     */
    private function createMatcherWithRealStepCalculator(IndustryActivityMaterial ...$materials): IndustryJobMatcher
    {
        $materialsByKey = [];
        foreach ($materials as $material) {
            $materialsByKey[$material->getTypeId() . '-' . $material->getActivityId()][] = $material;
        }
        $materialRepository = $this->createStub(IndustryActivityMaterialRepository::class);
        $materialRepository->method('findMaterialEntitiesForBlueprints')->willReturn($materialsByKey);

        $componentProduct = new IndustryActivityProduct();
        $componentProduct->setTypeId(self::COMPONENT_BLUEPRINT_TYPE_ID);
        $componentProduct->setActivityId(IndustryActivityType::Manufacturing->value);
        $componentProduct->setProductTypeId(self::COMPONENT_TYPE_ID);
        $componentProduct->setQuantity(1);
        $this->productRepository->method('findProductsForBlueprints')
            ->willReturn([self::COMPONENT_BLUEPRINT_TYPE_ID . '-1' => $componentProduct]);
        $this->productRepository->method('findOneBy')->willReturn($componentProduct);

        // Steps carry a structure config, bonus service stubbed => no structure/rig bonus.
        $realCalculationService = new IndustryCalculationService(
            $this->createStub(InvTypeRepository::class),
            $this->createStub(TypeNameResolver::class),
            $this->createStub(IndustryBonusService::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(IndustryUserSettingsRepository::class),
            $this->entityManager,
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
        );
        $realStepCalculator = new IndustryStepCalculator(
            $realCalculationService,
            $materialRepository,
            $this->productRepository,
            $this->entityManager,
        );

        return new IndustryJobMatcher(
            $realStepCalculator,
            $this->calculationService,
            $this->jobRepository,
            $this->productRepository,
            $this->structureConfigRepository,
            $this->entityManager,
        );
    }

    private function createComponentMaterialForFinalProduct(int $quantityPerRun): IndustryActivityMaterial
    {
        $material = new IndustryActivityMaterial();
        $material->setTypeId(self::BLUEPRINT_TYPE_ID);
        $material->setActivityId(IndustryActivityType::Manufacturing->value);
        $material->setMaterialTypeId(self::COMPONENT_TYPE_ID);
        $material->setQuantity($quantityPerRun);

        return $material;
    }

    private function createComponentStep(int $runs, ?string $splitGroupId = null, int $splitIndex = 0): IndustryProjectStep
    {
        $step = $this->createStep(runs: $runs, quantity: $runs, blueprintTypeId: self::COMPONENT_BLUEPRINT_TYPE_ID);
        $step->setProductTypeId(self::COMPONENT_TYPE_ID);
        $step->setDepth(1);
        $step->setMeLevel(0);
        $step->setStructureConfig($this->createStructureConfig('Planned Raitaru', self::STRUCTURE_LOCATION_ID));
        $step->setSplitGroupId($splitGroupId);
        $step->setSplitIndex($splitIndex);

        return $step;
    }

    private function createFinalProductStep(int $runs): IndustryProjectStep
    {
        $step = $this->createStep(runs: $runs, quantity: $runs);
        $step->setMeLevel(0);
        $step->setStructureConfig($this->createStructureConfig('Planned Raitaru', self::STRUCTURE_LOCATION_ID));

        return $step;
    }

    public function testAdaptedChildStepRunsAfterRecalculation(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        // The matcher shrinks the depth-1 step to the 5 matched runs, then the real
        // recalculation rewrites it from the parent needs (10 runs x 1 component, ME 0):
        // the shrink is silently undone, the step is back to 10 runs while only one
        // 5-run job is linked to it.
        $finalProductStep = $this->createFinalProductStep(runs: 10);
        $componentStep = $this->createComponentStep(runs: 10);
        $project = $this->createProject($finalProductStep, $componentStep);
        $this->givenJobsForBlueprint(self::COMPONENT_BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 5));

        $matcher = $this->createMatcherWithRealStepCalculator($this->createComponentMaterialForFinalProduct(1));
        $matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($componentStep));
        $this->assertSame(10, $componentStep->getRuns());
        $this->assertSame(10, $componentStep->getQuantity());
        $this->assertSame([], $this->matchedJobIds($finalProductStep));
        $this->assertSame(10, $finalProductStep->getRuns());
    }

    public function testSplitGroupAfterMatch(): void
    {
        // CARACTÉRISATION : comportement actuel, suspecté faux, cf. issue #3
        // Split group 5 + 5 runs, a single 3-run job: the matcher shrinks the first split to 3,
        // then the real recalculation redistributes the 10 needed runs proportionally to the
        // shrunk runs (3/8 and 5/8): first split 4 runs (linked job covers only 3),
        // second split grows to 6 runs.
        $finalProductStep = $this->createFinalProductStep(runs: 10);
        $firstSplit = $this->createComponentStep(runs: 5, splitGroupId: 'split-group-1', splitIndex: 0);
        $firstSplit->setTotalGroupRuns(10);
        $secondSplit = $this->createComponentStep(runs: 5, splitGroupId: 'split-group-1', splitIndex: 1);
        $secondSplit->setTotalGroupRuns(10);
        $project = $this->createProject($finalProductStep, $firstSplit, $secondSplit);
        $this->givenJobsForBlueprint(self::COMPONENT_BLUEPRINT_TYPE_ID, $this->createJob(jobId: 5001, runs: 3));

        $matcher = $this->createMatcherWithRealStepCalculator($this->createComponentMaterialForFinalProduct(1));
        $matcher->matchEsiJobs($project);

        $this->assertSame([5001], $this->matchedJobIds($firstSplit));
        $this->assertSame([], $this->matchedJobIds($secondSplit));
        $this->assertSame(4, $firstSplit->getRuns());
        $this->assertSame(4, $firstSplit->getQuantity());
        $this->assertSame(6, $secondSplit->getRuns());
        $this->assertSame(6, $secondSplit->getQuantity());
        $this->assertSame(10, $firstSplit->getTotalGroupRuns());
        $this->assertSame(10, $secondSplit->getTotalGroupRuns());
    }
}
