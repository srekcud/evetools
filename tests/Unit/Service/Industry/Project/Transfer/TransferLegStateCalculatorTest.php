<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Transfer;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStationTransfer;
use App\Entity\User;
use App\Service\Industry\Project\Courier\CourierQuoteCalculator;
use App\Service\Industry\Project\ProjectContainerContent;
use App\Service\Industry\Project\ProjectContainerReading;
use App\Service\Industry\Project\Transfer\StationTransferLineState;
use App\Service\Industry\Project\Transfer\StationTransferState;
use App\Enum\TransferCarrierKind;
use App\Dto\Transfer\TransferEndpoint;
use App\Service\Industry\Project\Transfer\TransferLegState;
use App\Service\Industry\Project\Transfer\TransferLegStateCalculator;
use App\Dto\Transfer\TransferLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TransferLegStateCalculator::class)]
#[Group('issue-1295')]
final class TransferLegStateCalculatorTest extends TestCase
{
    private const int JITA_SYSTEM = 30_000_142;
    private const int SYSTEM_S1 = 30_000_144;
    private const int SYSTEM_S2 = 30_002_187;
    private const int STATION_A = 100;
    private const int STATION_B = 200;
    private const int ZYDRINE = 39;
    private const int TRITANIUM = 34;
    private const string DECLARED_AT = '2026-11-22T12:00:00+00:00';
    private const string PREVIOUS_ROUTE = '01970000-0000-7000-8000-000000001295';

    public function testNothingLeftTheDepartureContainerSoTheLegIsAwaitingDeparture(): void
    {
        $leg = $this->zydrineLegBetweenStations(150, 0);

        $state = $this->compute($leg, [$this->readingAt(self::STATION_A, [self::ZYDRINE => 150]), $this->readingAt(self::STATION_B, [self::ZYDRINE => 0])]);

        self::assertSame(
            [$leg->getId()->toRfc4122(), $leg->getRouteId()->toRfc4122(), 0, self::STATION_B, StationTransferState::AwaitingDeparture, null],
            [$state->transferId, $state->routeId, $state->legIndex, $state->toLocationId, $state->state, $state->unknownReason],
        );
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 0, 0, 0, 'assets', 'assets', null]], $this->rowsOf($state));
    }

    public function testDepartureIsCappedAtTheQuantityWhenTheContainerDropsBeyondIt(): void
    {
        $state = $this->compute($this->zydrineLegBetweenStations(150, 0), [$this->readingAt(self::STATION_A, [self::ZYDRINE => 40]), $this->readingAt(self::STATION_B, [self::ZYDRINE => 0])]);

        self::assertSame(StationTransferState::InTransit, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 100, 0, 100, 'assets', 'assets', null]], $this->rowsOf($state));
    }

    public function testPartialArrivalAtTheArrivalContainerLeavesTheRestInTransit(): void
    {
        $state = $this->compute($this->zydrineLegBetweenStations(150, 0), [$this->readingAt(self::STATION_A, [self::ZYDRINE => 40]), $this->readingAt(self::STATION_B, [self::ZYDRINE => 70])]);

        self::assertSame(StationTransferState::InTransit, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 100, 70, 30, 'assets', 'assets', null]], $this->rowsOf($state));
    }

    public function testArrivalIsCappedAtTheQuantityAndTheLegIsArrived(): void
    {
        $state = $this->compute($this->zydrineLegBetweenStations(150, 0), [$this->readingAt(self::STATION_A, [self::ZYDRINE => 40]), $this->readingAt(self::STATION_B, [self::ZYDRINE => 130])]);

        self::assertSame(StationTransferState::Arrived, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 100, 100, 0, 'assets', 'assets', null]], $this->rowsOf($state));
    }

    public function testStockThatRoseAtDepartureOrFellAtArrivalNeverGivesANegativeQuantity(): void
    {
        $state = $this->compute($this->zydrineLegBetweenStations(150, 50), [$this->readingAt(self::STATION_A, [self::ZYDRINE => 170]), $this->readingAt(self::STATION_B, [self::ZYDRINE => 20])]);

        self::assertSame(StationTransferState::AwaitingDeparture, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 0, 0, 0, 'assets', 'assets', null]], $this->rowsOf($state));
    }

    public function testUnknownArrivalContainerGivesNullArrivedAndInTransitWithItsReasonAndAnUnknownLeg(): void
    {
        $arrivalReading = ProjectContainerReading::unknown('c-200', 'Ishtar 200', self::STATION_B, 'corporation_assets_not_synced', null);

        $state = $this->compute($this->zydrineLegBetweenStations(150, 0), [$this->readingAt(self::STATION_A, [self::ZYDRINE => 40]), $arrivalReading]);

        self::assertSame(StationTransferState::Unknown, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 100, null, null, 'assets', 'assets', 'corporation_assets_not_synced']], $this->rowsOf($state));
    }

    public function testUnknownDepartureContainerGivesNullDepartedAndInTransitWithItsReason(): void
    {
        $departureReading = ProjectContainerReading::unknown('c-100', 'Ishtar 100', self::STATION_A, 'container_not_found', null);

        $state = $this->compute($this->zydrineLegBetweenStations(150, 0), [$departureReading, $this->readingAt(self::STATION_B, [self::ZYDRINE => 70])]);

        self::assertSame(StationTransferState::Unknown, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, null, 70, null, 'assets', 'assets', 'container_not_found']], $this->rowsOf($state));
    }

    public function testDepartureWithoutProjectContainerIsTheDeclarationItselfAndArrivalIsStillMeasured(): void
    {
        $leg = $this->leg(0, TransferCarrierKind::Courier, new TransferEndpoint(self::JITA_SYSTEM, null), new TransferEndpoint(self::SYSTEM_S1, self::STATION_A), [new TransferLine(self::ZYDRINE, 100, null, 0)]);

        $state = $this->compute($leg, [$this->readingAt(self::STATION_A, [self::ZYDRINE => 70])]);

        self::assertSame(StationTransferState::InTransit, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 100, 70, 30, 'declared', 'assets', null]], $this->rowsOf($state));
    }

    public function testArrivalWithoutBaselineIsNotMeasurableAndStaysPendingAtZero(): void
    {
        $leg = $this->zydrineLegBetweenStations(150, null);

        $state = $this->compute($leg, [$this->readingAt(self::STATION_A, [self::ZYDRINE => 40]), $this->readingAt(self::STATION_B, [self::ZYDRINE => 90])]);

        self::assertSame(StationTransferState::InTransit, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 100, 0, 100, 'assets', 'pending', null]], $this->rowsOf($state));
    }

    public function testLaterLegDepartsWhatTheLegBeforeItArrivedAndMeasuresItsOwnArrival(): void
    {
        $leg = $this->leg(1, TransferCarrierKind::JumpBridge, new TransferEndpoint(self::SYSTEM_S2, null), new TransferEndpoint(self::SYSTEM_S1, self::STATION_A), [new TransferLine(self::ZYDRINE, 100, null, 10)]);
        $previous = $this->previousLegStateArrived(self::ZYDRINE, 60);

        $state = $this->compute($leg, [$this->readingAt(self::STATION_A, [self::ZYDRINE => 35])], $previous);

        self::assertSame(StationTransferState::InTransit, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 60, 25, 35, 'declared', 'assets', null]], $this->rowsOf($state));
    }

    public function testLaterLegWhosePreviousLegArrivalIsUnknownDepartsNothingKnown(): void
    {
        $leg = $this->leg(1, TransferCarrierKind::JumpBridge, new TransferEndpoint(self::SYSTEM_S2, null), new TransferEndpoint(self::SYSTEM_S1, self::STATION_A), [new TransferLine(self::ZYDRINE, 100, null, 10)]);
        $previous = $this->previousLegStateArrived(self::ZYDRINE, null);

        $state = $this->compute($leg, [$this->readingAt(self::STATION_A, [self::ZYDRINE => 35])], $previous);

        self::assertSame(StationTransferState::Unknown, $state->state);
        self::assertSame(
            [[null, 25, null]],
            array_map(static fn (StationTransferLineState $line): array => [$line->departed, $line->arrived, $line->inTransit], $state->lines),
        );
    }

    public function testManualArrivalPrimesOverAnUnknownContainerReadingAndCountsTheWholeQuantity(): void
    {
        $leg = $this->zydrineLegBetweenStations(150, 0)->markArrived(new \DateTimeImmutable('2026-11-22T14:00:00+00:00'));
        $arrivalReading = ProjectContainerReading::unknown('c-200', 'Ishtar 200', self::STATION_B, 'corporation_assets_not_synced', null);

        $state = $this->compute($leg, [$this->readingAt(self::STATION_A, [self::ZYDRINE => 40]), $arrivalReading]);

        self::assertSame(StationTransferState::Arrived, $state->state);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 100, 1.0, 100, 100, 0, 'assets', 'declared', null]], $this->rowsOf($state));
    }

    public function testLegWithOneLineInTransitAndOneNotDepartedIsInTransit(): void
    {
        $leg = $this->leg(
            0,
            TransferCarrierKind::JumpBridge,
            new TransferEndpoint(self::SYSTEM_S1, self::STATION_A),
            new TransferEndpoint(self::SYSTEM_S2, self::STATION_B),
            [new TransferLine(self::ZYDRINE, 100, 150, 0), new TransferLine(self::TRITANIUM, 3_000, 5_000, 200)],
        );

        $state = $this->compute($leg, [
            $this->readingAt(self::STATION_A, [self::ZYDRINE => 40, self::TRITANIUM => 5_000]),
            $this->readingAt(self::STATION_B, [self::ZYDRINE => 0, self::TRITANIUM => 200]),
        ]);

        self::assertSame(StationTransferState::InTransit, $state->state);
        self::assertSame(
            [
                [self::ZYDRINE, 'Zydrine', 100, 1.0, 100, 0, 100, 'assets', 'assets', null],
                [self::TRITANIUM, 'Tritanium', 3_000, 375.0, 0, 0, 0, 'assets', 'assets', null],
            ],
            $this->rowsOf($state),
        );
        self::assertEqualsWithDelta(376.0, $state->volume, 0.0000001);
        self::assertNull($state->unknownReason);
    }

    public function testVolumeIsQuantityTimesTheTypeVolumeRoundedToSixDecimalsAndSummedOverTheLines(): void
    {
        $leg = $this->leg(
            0,
            TransferCarrierKind::Pilot,
            new TransferEndpoint(self::SYSTEM_S1, self::STATION_A),
            new TransferEndpoint(self::SYSTEM_S1, self::STATION_B),
            [new TransferLine(self::ZYDRINE, 100, null, null), new TransferLine(self::TRITANIUM, 3, null, null)],
        );

        $state = $this->calculator()->compute($leg, [], null, [self::ZYDRINE => 0.01, self::TRITANIUM => 0.1234567], [self::ZYDRINE => 'Zydrine', self::TRITANIUM => 'Tritanium']);

        self::assertEqualsWithDelta([1.0, 0.37037], $this->lineVolumesOf($state), 0.0000001);
        self::assertEqualsWithDelta(1.37037, $state->volume, 0.0000001);
        self::assertNull($state->unknownReason);
    }

    public function testTypeWithoutVolumeGivesANullLegVolumeWithVolumeUnknownNeverZero(): void
    {
        $leg = $this->leg(
            0,
            TransferCarrierKind::Pilot,
            new TransferEndpoint(self::SYSTEM_S1, self::STATION_A),
            new TransferEndpoint(self::SYSTEM_S1, self::STATION_B),
            [new TransferLine(self::ZYDRINE, 100, null, null), new TransferLine(self::TRITANIUM, 3, null, null)],
        );

        $state = $this->calculator()->compute($leg, [], null, [self::ZYDRINE => null, self::TRITANIUM => 0.5], [self::ZYDRINE => 'Zydrine', self::TRITANIUM => 'Tritanium']);

        self::assertEquals([null, 1.5], $this->lineVolumesOf($state));
        self::assertNull($state->volume);
        self::assertSame('volume_unknown', $state->unknownReason);
    }

    public function testExpectedArrivalIsTheDeclarationPlusTheDurationOfTheCarrier(): void
    {
        $declaredAt = new \DateTimeImmutable(self::DECLARED_AT);
        $expectedByKind = [];
        foreach ([TransferCarrierKind::Pilot, TransferCarrierKind::JumpBridge, TransferCarrierKind::Courier] as $kind) {
            $leg = $this->leg(0, $kind, new TransferEndpoint(self::SYSTEM_S1, null), new TransferEndpoint(self::SYSTEM_S2, null), [new TransferLine(self::ZYDRINE, 100, null, null)]);
            $expectedByKind[$kind->value] = $this->compute($leg, [])->expectedArrivalAt->format(\DATE_ATOM);
        }

        self::assertSame(
            [
                'pilot' => $declaredAt->modify('+30 minutes')->format(\DATE_ATOM),
                'jump_bridge' => $declaredAt->modify('+120 minutes')->format(\DATE_ATOM),
                'courier' => $declaredAt->modify(\sprintf('+%d days', CourierQuoteCalculator::DAYS_TO_COMPLETE))->format(\DATE_ATOM),
            ],
            $expectedByKind,
        );
    }

    private function zydrineLegBetweenStations(?int $departureBaseline, ?int $arrivalBaseline): IndustryProjectStationTransfer
    {
        return $this->leg(
            0,
            TransferCarrierKind::JumpBridge,
            new TransferEndpoint(self::SYSTEM_S1, self::STATION_A),
            new TransferEndpoint(self::SYSTEM_S2, self::STATION_B),
            [new TransferLine(self::ZYDRINE, 100, $departureBaseline, $arrivalBaseline)],
        );
    }

    private function leg(int $legIndex, TransferCarrierKind $kind, TransferEndpoint $from, TransferEndpoint $to, array $lines): IndustryProjectStationTransfer
    {
        return new IndustryProjectStationTransfer(new IndustryProject(), new User(), Uuid::v7(), $legIndex, $kind, $from, $to, $lines, new \DateTimeImmutable(self::DECLARED_AT));
    }

    private function compute(IndustryProjectStationTransfer $leg, array $readings, ?TransferLegState $previous = null): TransferLegState
    {
        $readingsByLocationId = [];
        foreach ($readings as $reading) {
            $readingsByLocationId[(int) $reading->locationId] = $reading;
        }

        return $this->calculator()->compute(
            $leg,
            $readingsByLocationId,
            $previous,
            [self::ZYDRINE => 0.01, self::TRITANIUM => 0.125],
            [self::ZYDRINE => 'Zydrine', self::TRITANIUM => 'Tritanium'],
        );
    }

    private function calculator(): TransferLegStateCalculator
    {
        return new TransferLegStateCalculator();
    }

    private function readingAt(int $locationId, array $quantityByTypeId): ProjectContainerReading
    {
        $contents = [];
        foreach ($quantityByTypeId as $typeId => $quantity) {
            $contents[] = new ProjectContainerContent($typeId, null, $quantity);
        }

        return ProjectContainerReading::known('c-'.$locationId, 'Ishtar '.$locationId, $locationId, $contents, null);
    }

    private function previousLegStateArrived(int $typeId, ?int $arrived): TransferLegState
    {
        return new TransferLegState(
            'previous-leg',
            self::PREVIOUS_ROUTE,
            0,
            null,
            StationTransferState::InTransit,
            [new StationTransferLineState($typeId, 'Zydrine', 100, 1.0, 100, $arrived, null === $arrived ? null : 100 - $arrived, 'declared', 'declared', null)],
            1.0,
            null,
            new \DateTimeImmutable(self::DECLARED_AT),
        );
    }

    private function lineVolumesOf(TransferLegState $state): array
    {
        return array_map(static fn (StationTransferLineState $line): ?float => $line->volume, $state->lines);
    }

    private function rowsOf(TransferLegState $state): array
    {
        return array_map(
            static fn (StationTransferLineState $line): array => [
                $line->typeId,
                $line->typeName,
                $line->quantity,
                $line->volume,
                $line->departed,
                $line->arrived,
                $line->inTransit,
                $line->departureSource,
                $line->arrivalSource,
                $line->unknownReason,
            ],
            $state->lines,
        );
    }
}
