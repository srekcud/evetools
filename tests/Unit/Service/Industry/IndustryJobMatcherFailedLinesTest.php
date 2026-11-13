<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\CachedIndustryJob;
use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Exception\ProducerMissingFromSdeException;
use App\Repository\CachedIndustryJobRepository;
use App\Repository\IndustryProjectJobMatchRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\IndustryCalculationService;
use App\Service\Industry\IndustryJobMatcher;
use App\Service\Industry\LineMatchFailure;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Uid\Uuid;

#[CoversClass(IndustryJobMatcher::class)]
#[CoversClass(LineMatchFailure::class)]
#[Group('issue-798')]
final class IndustryJobMatcherFailedLinesTest extends TestCase
{
    private const int ISHTAR_TYPE_ID = 12005;
    private const int ISHTAR_BLUEPRINT_TYPE_ID = 12006;
    private const int UNKNOWN_TYPE_ID = 99_999_901;
    private const int RAM_TYPE_ID = 11478;
    private const int RAM_BLUEPRINT_TYPE_ID = 11479;
    private const string PROJECT_ID = '0196f6a2-7c1e-7d3a-9b40-798000000001';

    private BufferingLogger $logger;
    private IndustryJobMatcher $matcher;
    private User $owner;
    private Character $pilot;

    protected function setUp(): void
    {
        $this->owner = new User();
        $this->pilot = (new Character())->setName('Aria Solberg')->setEveCharacterId(2_112_000_001);
        (new \ReflectionProperty(Character::class, 'id'))->setValue($this->pilot, Uuid::v4());
        $this->owner->addCharacter($this->pilot);
        $this->logger = new BufferingLogger();

        $jobRepository = $this->createStub(CachedIndustryJobRepository::class);
        $jobRepository->method('findManufacturingJobsByBlueprint')->willReturnCallback(
            fn (int $blueprintTypeId): array => match ($blueprintTypeId) {
                self::ISHTAR_BLUEPRINT_TYPE_ID => [$this->job(7_980_001, 5, self::ISHTAR_BLUEPRINT_TYPE_ID)],
                self::RAM_BLUEPRINT_TYPE_ID => [$this->job(7_980_002, 4, self::RAM_BLUEPRINT_TYPE_ID)],
                default => [],
            },
        );

        $jobMatchRepository = $this->createStub(IndustryProjectJobMatchRepository::class);
        $jobMatchRepository->method('findEsiJobIdsMatchedToOtherProjects')->willReturn([]);

        $productRepository = $this->createStub(IndustryActivityProductRepository::class);
        $productRepository->method('producerOf')->willReturnCallback(
            static fn (int $productTypeId): IndustryActivityProduct => match ($productTypeId) {
                self::ISHTAR_TYPE_ID => self::producer(self::ISHTAR_BLUEPRINT_TYPE_ID, self::ISHTAR_TYPE_ID),
                self::RAM_TYPE_ID => self::producer(self::RAM_BLUEPRINT_TYPE_ID, self::RAM_TYPE_ID),
                default => throw new ProducerMissingFromSdeException($productTypeId),
            },
        );

        $this->matcher = new IndustryJobMatcher(
            $this->createStub(IndustryCalculationService::class),
            $jobRepository,
            $productRepository,
            $jobMatchRepository,
            $this->createStub(EntityManagerInterface::class),
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testALineWhoseProducerIsMissingFromTheSdeIsReportedAndTheOtherLinesAreMatched(): void
    {
        $unknownLine = $this->jobLine(self::UNKNOWN_TYPE_ID, 'Unknown Hull', 5);
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 5);
        $ramLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 4);

        $failures = $this->matcher->matchEsiJobs($this->project($unknownLine, $ishtarLine, $ramLine));

        self::assertSame(
            [['typeId' => self::UNKNOWN_TYPE_ID, 'typeName' => 'Unknown Hull', 'reason' => 'producer_missing_from_sde']],
            array_map(static fn (LineMatchFailure $failure): array => $failure->toArray(), $failures),
        );
        self::assertSame(
            [[], [7_980_001], [7_980_002]],
            [$this->matchedJobIdsOf($unknownLine), $this->matchedJobIdsOf($ishtarLine), $this->matchedJobIdsOf($ramLine)],
        );
    }

    public function testALineWithoutRunsIsReportedWithoutRaisingAndTheOtherLinesAreMatched(): void
    {
        $withoutRunsLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 5)->setRuns(null);
        $ramLine = $this->jobLine(self::RAM_TYPE_ID, 'R.A.M.- Starship Tech', 4);

        $failures = $this->matcher->matchEsiJobs($this->project($withoutRunsLine, $ramLine));

        self::assertSame(
            [['typeId' => self::ISHTAR_TYPE_ID, 'typeName' => 'Ishtar', 'reason' => 'no_runs']],
            array_map(static fn (LineMatchFailure $failure): array => $failure->toArray(), $failures),
        );
        self::assertSame([7_980_002], $this->matchedJobIdsOf($ramLine));
    }

    public function testEveryFailedLineIsLoggedAsAWarningWithItsProjectTypeAndReason(): void
    {
        $unknownLine = $this->jobLine(self::UNKNOWN_TYPE_ID, 'Unknown Hull', 5);
        $withoutRunsLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 5)->setRuns(null);

        $this->matcher->matchEsiJobs($this->project($unknownLine, $withoutRunsLine));

        self::assertSame(
            [
                ['warning', 'Failed to match jobs of line', ['projectId' => self::PROJECT_ID, 'typeId' => self::UNKNOWN_TYPE_ID, 'reason' => 'producer_missing_from_sde']],
                ['warning', 'Failed to match jobs of line', ['projectId' => self::PROJECT_ID, 'typeId' => self::ISHTAR_TYPE_ID, 'reason' => 'no_runs']],
            ],
            $this->logger->cleanLogs(),
        );
    }

    public function testReportsNothingAndLogsNothingWhenEveryLineIsMatchable(): void
    {
        $ishtarLine = $this->jobLine(self::ISHTAR_TYPE_ID, 'Ishtar', 5);

        $failures = $this->matcher->matchEsiJobs($this->project($ishtarLine));

        self::assertSame([[], []], [$failures, $this->logger->cleanLogs()]);
        self::assertSame([7_980_001], $this->matchedJobIdsOf($ishtarLine));
    }

    private static function producer(int $blueprintTypeId, int $productTypeId): IndustryActivityProduct
    {
        return (new IndustryActivityProduct())
            ->setTypeId($blueprintTypeId)
            ->setActivityId(IndustryActivityType::Manufacturing->value)
            ->setProductTypeId($productTypeId)
            ->setQuantity(1);
    }

    private function job(int $jobId, int $runs, int $blueprintTypeId): CachedIndustryJob
    {
        return (new CachedIndustryJob())
            ->setJobId($jobId)
            ->setRuns($runs)
            ->setStatus('active')
            ->setCost(1_000_000.0)
            ->setActivityId(IndustryActivityType::Manufacturing->value)
            ->setBlueprintTypeId($blueprintTypeId)
            ->setCharacter($this->pilot)
            ->setStartDate(new \DateTimeImmutable('2026-11-02 08:00:00 +00:00'))
            ->setEndDate(new \DateTimeImmutable('2026-11-04 08:00:00 +00:00'));
    }

    private function jobLine(int $typeId, string $typeName, int $runs): IndustryProjectBomLine
    {
        return (new IndustryProjectBomLine())
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setRequiredQuantity($runs)
            ->setIsJob(true)
            ->setJobGroup('final')
            ->setActivityType('manufacturing')
            ->setRuns($runs);
    }

    private function project(IndustryProjectBomLine ...$lines): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($this->owner);
        (new \ReflectionProperty(IndustryProject::class, 'id'))->setValue($project, Uuid::fromString(self::PROJECT_ID));
        (new \ReflectionProperty(IndustryProject::class, 'createdAt'))->setValue($project, new \DateTimeImmutable('2026-11-01 10:00:00 +00:00'));
        foreach ($lines as $line) {
            $project->addBomItem($line);
        }

        return $project;
    }

    /**
     * @return list<int>
     */
    private function matchedJobIdsOf(IndustryProjectBomLine $line): array
    {
        return array_values(array_map(
            static fn ($match): int => $match->getEsiJobId(),
            $line->getJobMatches()->toArray(),
        ));
    }
}
