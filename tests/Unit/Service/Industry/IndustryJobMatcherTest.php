<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
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
    private const string PROJECT_CREATED_AT = '2026-11-01 10:00:00';

    private CachedIndustryJobRepository $jobRepository;
    private IndustryProjectJobMatchRepository $jobMatchRepository;
    private IndustryActivityProductRepository $productRepository;
    private IndustryJobMatcher $matcher;

    private array $jobsByBlueprint = [];

    private array $jobsMatchedToProjects = [];

    private array $jobQueries = [];

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
            fn (IndustryProject $project): array => array_values(array_map(
                static fn (array $matched): int => $matched[1],
                array_filter($this->jobsMatchedToProjects, static fn (array $matched): bool => $matched[0] !== $project),
            )),
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

        $this->matcher = new IndustryJobMatcher(
            $calculationService,
            $this->jobRepository,
            $this->productRepository,
            $this->jobMatchRepository,
            $this->createStub(EntityManagerInterface::class),
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

    public function testOneLargerJobIsMatchedAfterTheJobsThatFitTheRemainingRunsOfTheLine(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 'final', self::ISHTAR_FINAL_LINE_RUNS, 'manufacturing');
        $this->givenJobs(
            self::ISHTAR_BLUEPRINT_TYPE_ID,
            $this->job(7_362_013, 3, '2026-11-02 08:00:00'),
            $this->job(7_362_017, 7, '2026-11-02 09:00:00'),
            $this->job(7_362_018, 8, '2026-11-02 10:00:00'),
        );

        $this->matcher->matchEsiJobs($this->project($ishtarLine));

        self::assertSame([7_362_013, 7_362_017], $this->matchedJobIdsOf($ishtarLine));
        self::assertSame(10, $ishtarLine->getMatchedRuns());
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

    private function project(IndustryProjectBomLine ...$lines): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner);
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
