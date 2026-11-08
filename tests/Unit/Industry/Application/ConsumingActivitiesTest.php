<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\ConsumingActivities;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Job;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\Plan;
use App\Industry\Domain\Quantity;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumingActivities::class)]
#[Group('issue-466')]
final class ConsumingActivitiesTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int HYDROCARBONS = 16633;
    private const int FULLERITE = 30375;

    public function testMaterialConsumedOnlyByReactionsIsDeliveredToTheReactionWorkshop(): void
    {
        $activities = ConsumingActivities::of($this->planOf(
            $this->job(ActivityKind::Reaction, [self::HYDROCARBONS => 100]),
            $this->job(ActivityKind::Reaction, [self::HYDROCARBONS => 50]),
        ));

        self::assertSame([self::HYDROCARBONS => ActivityKind::Reaction], $activities);
    }

    public function testMaterialConsumedByAManufacturingJobIsDeliveredToTheManufacturingWorkshop(): void
    {
        $activities = ConsumingActivities::of($this->planOf(
            $this->job(ActivityKind::Manufacturing, [self::TRITANIUM => 100]),
            $this->job(ActivityKind::Reaction, [self::FULLERITE => 10]),
        ));

        self::assertSame([self::TRITANIUM => ActivityKind::Manufacturing, self::FULLERITE => ActivityKind::Reaction], $activities);
    }

    public function testMaterialConsumedByBothKindsOfJobsIsDeliveredToTheManufacturingWorkshop(): void
    {
        $activities = ConsumingActivities::of($this->planOf(
            $this->job(ActivityKind::Reaction, [self::PYERITE => 10]),
            $this->job(ActivityKind::Manufacturing, [self::PYERITE => 20]),
        ));

        self::assertSame([self::PYERITE => ActivityKind::Manufacturing], $activities);
    }

    public function testMaterialConsumedByNoJobIsAbsent(): void
    {
        self::assertSame([], ConsumingActivities::of($this->planOf()));
    }

    private function planOf(Job ...$jobs): Plan
    {
        return new Plan(array_values($jobs), [], []);
    }

    /**
     * @param array<int, int> $materials quantity by typeId
     */
    private function job(ActivityKind $activity, array $materials): Job
    {
        return new Job(
            9999,
            $activity,
            new Runs(1),
            new MaterialEfficiency(0),
            new TimeEfficiency(0),
            array_map(static fn (int $quantity): Quantity => new Quantity($quantity), $materials),
        );
    }
}
