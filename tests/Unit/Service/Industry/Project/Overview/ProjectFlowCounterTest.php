<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Overview;

use App\Service\Industry\Project\Overview\ProjectFlowCounter;
use App\Service\Industry\Project\StationQuantity;
use App\Service\Industry\Project\TypeStationQuantities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectFlowCounter::class)]
#[Group('issue-1306')]
final class ProjectFlowCounterTest extends TestCase
{
    private const int AZBEL_ID = 1_040_000_001_000;
    private const int TATARA_ID = 1_040_000_002_000;
    private const int TRITANIUM_TYPE_ID = 34;
    private const int HULL_TYPE_ID = 12_005;

    public function testEachTypeAndStationCardCountsInTheColumnsItHasQuantityIn(): void
    {
        $flow = (new ProjectFlowCounter())->count([
            $this->type(self::TRITANIUM_TYPE_ID, 'Tritanium', [
                $this->card(self::AZBEL_ID, toBring: 500, onSite: 0, out: 0),
                $this->card(self::TATARA_ID, toBring: 0, onSite: 200, out: 0),
            ]),
            $this->type(self::HULL_TYPE_ID, 'Ishtar', [
                $this->card(self::AZBEL_ID, toBring: 0, onSite: 0, out: 0, inProduction: 3),
            ]),
        ], 0);

        self::assertSame([1, 1, 1, 0, 0, 0, null], [$flow->toBring, $flow->onSite, $flow->inProduction, $flow->out, $flow->done, $flow->unknown, $flow->reason]);
    }

    public function testWhatIsInTransitCountsAsToBring(): void
    {
        $flow = (new ProjectFlowCounter())->count([
            $this->type(self::TRITANIUM_TYPE_ID, 'Tritanium', [$this->card(self::AZBEL_ID, toBring: 0, onSite: 0, out: 0, inTransit: 40)]),
        ], 0);

        self::assertSame(1, $flow->toBring);
    }

    public function testOneCardWithSeveralQuantitiesCountsOncePerColumn(): void
    {
        $flow = (new ProjectFlowCounter())->count([
            $this->type(self::TRITANIUM_TYPE_ID, 'Tritanium', [$this->card(self::AZBEL_ID, toBring: 5, onSite: 10, out: 7, inProduction: 2)]),
        ], 0);

        self::assertSame([1, 1, 1, 1], [$flow->toBring, $flow->onSite, $flow->inProduction, $flow->out]);
    }

    public function testAQuantityThatCannotBeReadCountsInNoColumnAndMakesOneUnknownCard(): void
    {
        $flow = (new ProjectFlowCounter())->count([
            $this->type(self::TRITANIUM_TYPE_ID, 'Tritanium', [
                $this->card(self::AZBEL_ID, toBring: 5, onSite: null, out: 0, unknownReason: 'no_container_at_station'),
                $this->card(self::TATARA_ID, toBring: 0, onSite: 4, out: 0, inTransit: null),
            ]),
        ], 0);

        self::assertSame([1, 1, 0, 0, 2], [$flow->toBring, $flow->onSite, $flow->inProduction, $flow->out, $flow->unknown]);
    }

    public function testATypeWhosePlanCannotBeReadMakesTheWholeFlowUnavailableWithItsReason(): void
    {
        $flow = (new ProjectFlowCounter())->count([
            $this->type(self::TRITANIUM_TYPE_ID, 'Tritanium', [$this->card(self::AZBEL_ID, toBring: 5, onSite: 0, out: 0)]),
            new TypeStationQuantities(self::HULL_TYPE_ID, 'Ishtar', ['line-ishtar'], null, 'plan_unavailable'),
        ], 2);

        self::assertSame([null, null, null, null, null, 0, 'plan_unavailable'], [$flow->toBring, $flow->onSite, $flow->inProduction, $flow->out, $flow->done, $flow->unknown, $flow->reason]);
    }

    public function testTheSoldTypeCountIsTheDoneColumn(): void
    {
        $flow = (new ProjectFlowCounter())->count([], 2);

        self::assertSame([0, 0, 0, 0, 2], [$flow->toBring, $flow->onSite, $flow->inProduction, $flow->out, $flow->done]);
    }

    /**
     * @param list<StationQuantity> $stations
     */
    private function type(int $typeId, string $typeName, array $stations): TypeStationQuantities
    {
        return new TypeStationQuantities($typeId, $typeName, ['line-'.$typeId], $stations, null);
    }

    private function card(int $locationId, int $toBring, ?int $onSite, int $out, int $inProduction = 0, ?int $inTransit = 0, ?string $unknownReason = null): StationQuantity
    {
        return new StationQuantity($locationId, 'Station '.$locationId, 100, 0, $inProduction, $onSite, $out, $toBring, $inTransit, $unknownReason);
    }
}
