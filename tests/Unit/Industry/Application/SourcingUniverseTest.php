<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\PlannedProduction;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\SourcingUniverse;
use App\Industry\Application\StructureChoice;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Intermediate;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Tests\Unit\Industry\Application\Double\ProductionStructures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourcingUniverse::class)]
final class SourcingUniverseTest extends TestCase
{
    private const int TARGET_PRODUCT = 12005;
    private const int FERNITE_COMPOSITE = 16670;
    private const int LEAF_ALPHA = 16633;
    private const int LEAF_BETA = 16634;

    public function testAProductBuiltByJobsOfSeveralActivitiesIsWorkedByTheFirstJobActivityAtTheFirstJobStructure(): void
    {
        $manufacturingStation = ProductionStructures::raitaru('S1', solarSystemId: 30_000_772);
        $reactionStation = ProductionStructures::tatara('R1', solarSystemId: 30_002_659);
        $manufacturingJob = $this->job(self::FERNITE_COMPOSITE, ActivityKind::Manufacturing, 2, [self::LEAF_ALPHA => 30]);
        $reactionJob = $this->job(self::FERNITE_COMPOSITE, ActivityKind::Reaction, 3, [self::LEAF_BETA => 70]);
        $jobOfTheTarget = $this->job(self::TARGET_PRODUCT, ActivityKind::Manufacturing, 1, [self::FERNITE_COMPOSITE => 40]);

        $universe = SourcingUniverse::of(new PlannedProduction(
            new Plan(
                [$jobOfTheTarget, $manufacturingJob, $reactionJob],
                [self::LEAF_ALPHA => new Quantity(30), self::LEAF_BETA => new Quantity(70)],
                [self::FERNITE_COMPOSITE => new Intermediate(new Quantity(40), new Quantity(0), new Quantity(45), new Quantity(5), [$manufacturingJob, $reactionJob])],
            ),
            [
                PlannedProduction::choiceKey(self::TARGET_PRODUCT, ActivityKind::Manufacturing) => $this->assigned(ProductionStructures::raitaru('S0', solarSystemId: 30_000_001)),
                PlannedProduction::choiceKey(self::FERNITE_COMPOSITE, ActivityKind::Manufacturing) => $this->assigned($manufacturingStation),
                PlannedProduction::choiceKey(self::FERNITE_COMPOSITE, ActivityKind::Reaction) => $this->assigned($reactionStation),
            ],
        ));

        self::assertSame(ActivityKind::Manufacturing, $universe->activityByProduct[self::FERNITE_COMPOSITE]);
        self::assertSame($manufacturingStation, $universe->builderByProduct[self::FERNITE_COMPOSITE]);
        self::assertSame([$manufacturingJob, $reactionJob], $universe->jobsByProduct[self::FERNITE_COMPOSITE]);
        self::assertSame([self::FERNITE_COMPOSITE], $universe->builtTypeIds);
        self::assertSame(45, $universe->producedOf(self::FERNITE_COMPOSITE));
        self::assertSame([self::LEAF_ALPHA, self::LEAF_BETA], $universe->leafTypeIds);
    }

    private function assigned(ProductionStructure $structure): StructureChoice
    {
        return new StructureChoice($structure, StructureChoiceStatus::Assigned, Multiplier::one());
    }

    private function job(int $productTypeId, ActivityKind $activity, int $runs, array $materialQuantities): Job
    {
        return new Job(
            $productTypeId,
            $activity,
            new Runs($runs),
            new MaterialEfficiency(0),
            new TimeEfficiency(0),
            array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $materialQuantities),
        );
    }
}
