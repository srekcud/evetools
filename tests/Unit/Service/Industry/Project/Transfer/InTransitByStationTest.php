<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Transfer;

use App\Service\Industry\Project\Transfer\InTransitByStation;
use App\Service\Industry\Project\Transfer\StationTransferLineState;
use App\Service\Industry\Project\Transfer\StationTransferState;
use App\Service\Industry\Project\Transfer\TransferLegState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(InTransitByStation::class)]
#[Group('issue-1295')]
final class InTransitByStationTest extends TestCase
{
    private const int STATION_A = 100;
    private const int STATION_B = 200;
    private const int ZYDRINE = 39;
    private const int TRITANIUM = 34;
    private const string ROUTE_ONE = '01970000-0000-7000-8000-000000001295';
    private const string ROUTE_TWO = '01970000-0000-7000-8000-000000002295';

    public function testRouteCountsTheDepartureOfItsFirstLegAndTheArrivalOfItsLastLegAtTheLastStation(): void
    {
        $legStates = [
            $this->legState(self::ROUTE_ONE, 1, self::STATION_A, [self::ZYDRINE => [60, 20]]),
            $this->legState(self::ROUTE_ONE, 0, null, [self::ZYDRINE => [100, 60]]),
        ];

        self::assertSame([self::STATION_A => [self::ZYDRINE => 80]], InTransitByStation::of($legStates));
    }

    public function testRouteWhoseLastLegIsFullyArrivedIsAZeroEntryNotAMissingOne(): void
    {
        $legStates = [$this->legState(self::ROUTE_ONE, 0, self::STATION_A, [self::ZYDRINE => [100, 100], self::TRITANIUM => [30, 45]])];

        self::assertSame([self::STATION_A => [self::ZYDRINE => 0, self::TRITANIUM => 0]], InTransitByStation::of($legStates));
    }

    public function testSeveralRoutesToTheSameStationAreSummedPerType(): void
    {
        $legStates = [
            $this->legState(self::ROUTE_ONE, 0, self::STATION_A, [self::ZYDRINE => [100, 30], self::TRITANIUM => [500, 0]]),
            $this->legState(self::ROUTE_TWO, 0, self::STATION_A, [self::ZYDRINE => [25, 5]]),
        ];

        self::assertSame([self::STATION_A => [self::ZYDRINE => 90, self::TRITANIUM => 500]], InTransitByStation::of($legStates));
    }

    public function testUnknownDepartureOrArrivalGivesNullAndNullPoisonsTheSumOfTheStation(): void
    {
        $legStates = [
            $this->legState(self::ROUTE_ONE, 0, self::STATION_A, [self::ZYDRINE => [null, 0], self::TRITANIUM => [500, null]]),
            $this->legState(self::ROUTE_TWO, 0, self::STATION_A, [self::ZYDRINE => [25, 5], self::TRITANIUM => [10, 0]]),
        ];

        self::assertSame([self::STATION_A => [self::ZYDRINE => null, self::TRITANIUM => null]], InTransitByStation::of($legStates));
    }

    public function testRouteThatDoesNotEndAtAStationLocationIsIgnored(): void
    {
        $legStates = [
            $this->legState(self::ROUTE_ONE, 0, null, [self::ZYDRINE => [100, 0]]),
            $this->legState(self::ROUTE_TWO, 0, self::STATION_B, [self::TRITANIUM => [80, 20]]),
        ];

        self::assertSame([self::STATION_B => [self::TRITANIUM => 60]], InTransitByStation::of($legStates));
    }

    private function legState(string $routeId, int $legIndex, ?int $toLocationId, array $departedAndArrivedByTypeId): TransferLegState
    {
        $lines = [];
        foreach ($departedAndArrivedByTypeId as $typeId => [$departed, $arrived]) {
            $lines[] = new StationTransferLineState(
                $typeId,
                'Type '.$typeId,
                100,
                null,
                $departed,
                $arrived,
                null === $departed || null === $arrived ? null : max(0, $departed - $arrived),
                StationTransferLineState::SOURCE_DECLARED,
                StationTransferLineState::SOURCE_ASSETS,
                null,
            );
        }

        return new TransferLegState(
            \sprintf('%s-leg-%d', $routeId, $legIndex),
            $routeId,
            $legIndex,
            $toLocationId,
            StationTransferState::InTransit,
            $lines,
            null,
            null,
            new \DateTimeImmutable('2026-11-22T12:00:00+00:00'),
        );
    }
}
