<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Service\Industry\Project\JobMaterialNeedsMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobMaterialNeedsMapper::class)]
#[Group('issue-449')]
final class JobMaterialNeedsMapperTest extends TestCase
{
    use BuildsChainBomLines;

    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int PRODUCT = 449_201;
    private const int COMPONENT = 449_202;
    private const int REACTION = 449_203;

    public function testFinalAndComponentLinesGetTheMaterialsOfTheirPlanJob(): void
    {
        $product = $this->jobLine(self::PRODUCT, 'Product', 'final', null, runs: 10);
        $component = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 4);
        $productJob = $this->job(self::PRODUCT, 10, [self::COMPONENT => 40, self::TRITANIUM => 500]);
        $componentJob = $this->job(self::COMPONENT, 4, [self::PYERITE => 120]);

        $needs = JobMaterialNeedsMapper::map([$product, $component], $this->planOf([$productJob, $componentJob], [self::COMPONENT => [$componentJob]]));

        self::assertSame(
            [
                self::lineId($product) => [self::COMPONENT => 40, self::TRITANIUM => 500],
                self::lineId($component) => [self::PYERITE => 120],
            ],
            $needs,
        );
    }

    public function testReactionLineIsMatchedToTheReactionJob(): void
    {
        $reaction = $this->jobLine(self::REACTION, 'Reaction', 'component', self::COMPONENT, runs: 6, activityType: 'reaction', meLevel: 0, teLevel: 0);
        $reactionJob = $this->job(self::REACTION, 6, [self::TRITANIUM => 60], ActivityKind::Reaction, 0, 0);

        $needs = JobMaterialNeedsMapper::map([$reaction], $this->planOf([$reactionJob], [self::REACTION => [$reactionJob]]));

        self::assertSame([self::lineId($reaction) => [self::TRITANIUM => 60]], $needs);
    }

    public function testLinesSplitOverSeveralJobsAreMatchedByDescendingRuns(): void
    {
        $smallLine = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 5);
        $bigLine = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 10);
        $smallJob = $this->job(self::COMPONENT, 5, [self::TRITANIUM => 50]);
        $bigJob = $this->job(self::COMPONENT, 10, [self::TRITANIUM => 100]);

        $needs = JobMaterialNeedsMapper::map(
            [$smallLine, $bigLine],
            $this->planOf([$smallJob, $bigJob], [self::COMPONENT => [$smallJob, $bigJob]]),
        );

        self::assertSame(
            [self::lineId($smallLine) => [self::TRITANIUM => 50], self::lineId($bigLine) => [self::TRITANIUM => 100]],
            $needs,
        );
    }

    public function testLineWhoseRunsDifferFromThePlanJobIsNotMatched(): void
    {
        $line = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 10);
        $job = $this->job(self::COMPONENT, 12, [self::TRITANIUM => 120]);

        self::assertSame([], JobMaterialNeedsMapper::map([$line], $this->planOf([$job], [self::COMPONENT => [$job]])));
    }

    public function testLineWhoseMaterialEfficiencyDiffersFromThePlanJobIsNotMatched(): void
    {
        $line = $this->jobLine(self::COMPONENT, 'Component', 'component', self::PRODUCT, runs: 10, meLevel: 8);
        $job = $this->job(self::COMPONENT, 10, [self::TRITANIUM => 100]);

        self::assertSame([], JobMaterialNeedsMapper::map([$line], $this->planOf([$job], [self::COMPONENT => [$job]])));
    }

    public function testComponentLineIsNotMatchedToAFinalJobOfTheSameProduct(): void
    {
        $componentLine = $this->jobLine(self::PRODUCT, 'Product', 'component', self::COMPONENT, runs: 10);
        $finalJob = $this->job(self::PRODUCT, 10, [self::TRITANIUM => 100]);

        self::assertSame([], JobMaterialNeedsMapper::map([$componentLine], $this->planOf([$finalJob], [])));
    }

    public function testCopyInventionAndMaterialLinesGetNoNeeds(): void
    {
        $copy = $this->jobLine(self::COMPONENT, 'Component', 'blueprint', self::PRODUCT, runs: 10, activityType: 'copy');
        $invention = $this->jobLine(self::PRODUCT, 'Product', 'blueprint', null, runs: 10, activityType: 'invention');
        $material = $this->materialLine(self::TRITANIUM, 'Tritanium', 100, 0);
        $job = $this->job(self::COMPONENT, 10, [self::TRITANIUM => 100]);

        self::assertSame([], JobMaterialNeedsMapper::map([$copy, $invention, $material], $this->planOf([$job], [self::COMPONENT => [$job]])));
    }

    /**
     * @param array<int, int> $materials
     */
    private function job(int $productTypeId, int $runs, array $materials, ActivityKind $activity = ActivityKind::Manufacturing, int $meLevel = 10, int $teLevel = 20): Job
    {
        return new Job(
            $productTypeId,
            $activity,
            new Runs($runs),
            new MaterialEfficiency($meLevel),
            new TimeEfficiency($teLevel),
            array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $materials),
        );
    }

    /**
     * @param list<Job>                $jobs
     * @param array<int, list<Job>>    $intermediateJobs
     */
    private function planOf(array $jobs, array $intermediateJobs): Plan
    {
        $intermediates = array_map(
            static fn (array $jobsOfIntermediate): Intermediate => new Intermediate(new Quantity(1), new Quantity(0), new Quantity(1), new Quantity(0), $jobsOfIntermediate),
            $intermediateJobs,
        );

        return new Plan($jobs, [], $intermediates);
    }
}
