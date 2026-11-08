<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Industry\Application\PlannedProduction;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Service\Industry\Project\ProjectChainLevels;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectChainLevels::class)]
#[Group('issue-418')]
final class ProjectChainLevelsTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PRODUCT = 418_001;
    private const int COMPONENT = 418_002;
    private const int SUB_COMPONENT = 418_003;
    private const int OTHER_COMPONENT = 418_004;
    private const int SECOND_PRODUCT = 418_005;
    private const int FULLERENE = 16_670;

    public function testJobBuiltFromBoughtMaterialsOnlyIsLevelOne(): void
    {
        $levels = $this->levelsOf([$this->job(self::PRODUCT, [self::TRITANIUM])]);

        self::assertEquals([self::PRODUCT => 1], $levels);
    }

    public function testEachJobIsOneLevelAboveTheHighestManufacturedMaterialItConsumes(): void
    {
        $levels = $this->levelsOf([
            $this->job(self::PRODUCT, [self::TRITANIUM, self::COMPONENT]),
            $this->job(self::COMPONENT, [self::SUB_COMPONENT]),
            $this->job(self::SUB_COMPONENT, [self::TRITANIUM]),
        ]);

        self::assertEquals([self::PRODUCT => 3, self::COMPONENT => 2, self::SUB_COMPONENT => 1], $levels);
    }

    public function testIntermediateUsedByTwoConsumersHasOneLevelFromItsOwnMaterials(): void
    {
        $levels = $this->levelsOf([
            $this->job(self::PRODUCT, [self::COMPONENT, self::OTHER_COMPONENT]),
            $this->job(self::OTHER_COMPONENT, [self::COMPONENT]),
            $this->job(self::COMPONENT, [self::TRITANIUM]),
        ]);

        self::assertEquals([self::PRODUCT => 3, self::OTHER_COMPONENT => 2, self::COMPONENT => 1], $levels);
    }

    public function testReactionConsumedByAManufacturingJobCountsAsAManufacturedMaterial(): void
    {
        $levels = $this->levelsOf([
            $this->job(self::PRODUCT, [self::FULLERENE]),
            $this->job(self::FULLERENE, [self::TRITANIUM], ActivityKind::Reaction),
        ]);

        self::assertEquals([self::PRODUCT => 2, self::FULLERENE => 1], $levels);
    }

    public function testTargetConsumingAnotherTargetKeepsItsOwnLevel(): void
    {
        $levels = $this->levelsOf([
            $this->job(self::SECOND_PRODUCT, [self::PRODUCT]),
            $this->job(self::PRODUCT, [self::TRITANIUM]),
        ]);

        self::assertEquals([self::SECOND_PRODUCT => 2, self::PRODUCT => 1], $levels);
    }

    public function testTwoJobsOfTheSameProductShareOneLevel(): void
    {
        $levels = $this->levelsOf([
            $this->job(self::PRODUCT, [self::COMPONENT]),
            $this->job(self::COMPONENT, [self::TRITANIUM]),
            $this->job(self::COMPONENT, [self::TRITANIUM]),
        ]);

        self::assertEquals([self::PRODUCT => 2, self::COMPONENT => 1], $levels);
    }

    public function testPlanWithoutJobHasNoLevel(): void
    {
        self::assertEquals([], $this->levelsOf([]));
    }

    public function testMaterialCycleIsRefusedNotMaskedAsALevel(): void
    {
        $this->expectException(\LogicException::class);

        $this->levelsOf([
            $this->job(self::COMPONENT, [self::OTHER_COMPONENT]),
            $this->job(self::OTHER_COMPONENT, [self::COMPONENT]),
        ]);
    }

    /**
     * @param list<Job> $jobs
     * @return array<int, int>
     */
    private function levelsOf(array $jobs): array
    {
        return (new ProjectChainLevels())->of(new PlannedProduction(new Plan($jobs, [], []), []));
    }

    /**
     * @param list<int> $materialTypeIds
     */
    private function job(int $productTypeId, array $materialTypeIds, ActivityKind $activity = ActivityKind::Manufacturing): Job
    {
        $materials = [];
        foreach ($materialTypeIds as $materialTypeId) {
            $materials[$materialTypeId] = new Quantity(10);
        }

        return new Job($productTypeId, $activity, new Runs(1), new MaterialEfficiency(0), new TimeEfficiency(0), $materials);
    }
}
