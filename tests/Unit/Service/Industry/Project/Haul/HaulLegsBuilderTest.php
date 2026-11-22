<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Haul;

use App\Enum\StructureAccessStatus;
use App\Service\Industry\Project\Haul\HaulLegs;
use App\Service\Industry\Project\Haul\HaulLegsBuilder;
use App\Service\Industry\Project\Haul\HaulPlace;
use App\Service\Industry\Project\Haul\HaulReadings;
use App\Service\Industry\Project\PurchaseDeliveryState;
use App\Service\Industry\Project\PurchaseOriginKind;
use App\Service\Industry\Project\PurchaseToDeliver;
use App\Service\Industry\Project\StationQuantity;
use App\Service\Industry\Project\Transfer\StationTransferLineState;
use App\Service\Industry\Project\Transfer\StationTransferState;
use App\Service\Industry\Project\Transfer\TransferLegState;
use App\Service\Industry\Project\TypeStationQuantities;
use App\Service\Industry\Stock\PersonalStock;
use App\Service\Industry\Stock\PersonalStockContainerReading;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(HaulLegsBuilder::class)]
#[Group('issue-1364')]
final class HaulLegsBuilderTest extends TestCase
{
    private const int STATION_A = 1_035_000_000_001;
    private const int STATION_B = 1_035_000_000_002;
    private const int STATION_D = 1_035_000_000_003;
    private const int STATION_C = 60_003_760;
    private const int JITA = 30_000_142;
    private const int OTHER_HUB = 30_001_001;
    private const int COMPONENT_X = 21_001;
    private const int ZYDRINE = 39;
    private const int NOCXIUM = 38;
    private const int MORPHITE = 11_399;
    private const int CHARACTER_ONE = 2_112_000_001;
    private const int CHARACTER_TWO = 2_112_000_002;

    private HaulLegsBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new HaulLegsBuilder();
    }

    public function testComponentReadyAtAnotherPlanStationIsOneLegToTheStationThatNeedsIt(): void
    {
        $legs = $this->builder->build($this->readings([
            $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 40), $this->station(self::STATION_B, toBring: 40)),
        ]));

        self::assertSame([$this->leg('1035000000001>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'ready', 'project', 10.0, 400.0)])], $this->legsOf($legs));
        self::assertEquals(new HaulPlace(self::STATION_A, 'Azbel A', 30_000_001, 'Alpha'), $legs->legs[0]->departure);
        self::assertEquals(new HaulPlace(self::STATION_B, 'Sotiyo B', 30_000_002, 'Beta'), $legs->legs[0]->arrival);
        self::assertSame([], $legs->unreadable);
        self::assertNull($legs->personalStockUnknownReason);
    }

    public function testQuantityStillInProductionIsALineWithTheLatestEndDateOfItsJobs(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, inProduction: 40), $this->station(self::STATION_B, toBring: 40))],
            productionEnd: [self::STATION_A => [self::COMPONENT_X => new \DateTimeImmutable('2026-11-23T10:00:00+00:00')]],
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'in_production', 'project', 10.0, 400.0, readyAt: '2026-11-23T10:00:00+00:00')])],
            $this->legsOf($legs),
        );
    }

    public function testReadyPartAndProductionPartOfTheSameStationShareOneLegReadyFirst(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, inProduction: 25, out: 15), $this->station(self::STATION_B, toBring: 40))],
            productionEnd: [self::STATION_A => [self::COMPONENT_X => new \DateTimeImmutable('2026-11-23T14:00:00+00:00')]],
        ));

        self::assertSame([$this->leg('1035000000001>1035000000002', 400.0, [
            $this->line(self::COMPONENT_X, 15, 'ready', 'project', 10.0, 150.0),
            $this->line(self::COMPONENT_X, 25, 'in_production', 'project', 10.0, 250.0, readyAt: '2026-11-23T14:00:00+00:00'),
        ])], $this->legsOf($legs));
    }

    public function testProductionWithoutAnyEndDateGivesAnUnknownReadyAt(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, inProduction: 40), $this->station(self::STATION_B, toBring: 40))],
            productionEnd: [self::STATION_A => [self::COMPONENT_X => null]],
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'in_production', 'project', 10.0, 400.0)])],
            $this->legsOf($legs),
        );
    }

    public function testProductionQuantityOfAStationIsReducedByWhatItsOwnStepsAndTheRoadAlreadyTake(): void
    {
        $legs = $this->builder->build($this->readings([
            $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, inProduction: 40, toBring: 15, inTransit: 5), $this->station(self::STATION_B, toBring: 40)),
        ]));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 200.0, [$this->line(self::COMPONENT_X, 20, 'in_production', 'project', 10.0, 200.0)])],
            $this->legsOf($legs),
        );
    }

    public function testFirstStationThatHoldsTheWholeQuantityServesItAlone(): void
    {
        $legs = $this->builder->build($this->readings([
            $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 10), $this->station(self::STATION_B, toBring: 40), $this->station(self::STATION_C, out: 50)),
        ]));

        self::assertSame(
            [$this->leg('60003760>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'ready', 'project', 10.0, 400.0)])],
            $this->legsOf($legs),
        );
        self::assertEquals(new HaulPlace(self::STATION_C, 'Jita IV - Moon 4', self::JITA, 'Jita'), $legs->legs[0]->departure);
    }

    public function testWhenNoStationHoldsEverythingEachGivesWhatItHasAndTheRestHasNoLeg(): void
    {
        $legs = $this->builder->build($this->readings([
            $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 10), $this->station(self::STATION_B, toBring: 40), $this->station(self::STATION_C, out: 20)),
        ]));

        self::assertSame([
            $this->leg('1035000000001>1035000000002', 100.0, [$this->line(self::COMPONENT_X, 10, 'ready', 'project', 10.0, 100.0)]),
            $this->leg('60003760>1035000000002', 200.0, [$this->line(self::COMPONENT_X, 20, 'ready', 'project', 10.0, 200.0)]),
        ], $this->legsOf($legs));
        self::assertSame([], $legs->unreadable);
    }

    public function testStationQuantityGivenToOneDemandIsNoLongerAvailableToTheNextOne(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 50), $this->station(self::STATION_B, toBring: 30), $this->station(self::STATION_D, toBring: 40))],
            planLocationIds: [self::STATION_A, self::STATION_B, self::STATION_D],
        ));

        self::assertSame([
            $this->leg('1035000000001>1035000000002', 300.0, [$this->line(self::COMPONENT_X, 30, 'ready', 'project', 10.0, 300.0)]),
            $this->leg('1035000000001>1035000000003', 200.0, [$this->line(self::COMPONENT_X, 20, 'ready', 'project', 10.0, 200.0)]),
        ], $this->legsOf($legs));
    }

    public function testStationWithAnUnreadableOutQuantityIsNotADeparture(): void
    {
        $legs = $this->builder->build($this->readings([
            $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: null, inTransit: null), $this->station(self::STATION_B, toBring: 40), $this->station(self::STATION_C, out: 40)),
        ]));

        self::assertSame(
            [$this->leg('60003760>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'ready', 'project', 10.0, 400.0)])],
            $this->legsOf($legs),
        );
    }

    public function testStationAndPersonalStockOfTheArrivalStationAreNeverItsOwnDeparture(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 10), $this->station(self::STATION_B, out: 5, toBring: 40))],
            personalStock: $this->personalStock(self::COMPONENT_X, [[self::STATION_B, 'Sotiyo B', 'Beta', 50]]),
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 100.0, [$this->line(self::COMPONENT_X, 10, 'ready', 'project', 10.0, 100.0)])],
            $this->legsOf($legs),
        );
    }

    public function testPurchaseNotYetAtTheContainerLeavesFromThePurchaseSystemWithoutStation(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_A), $this->station(self::STATION_B, toBring: 100))],
            purchases: [$this->purchase(self::ZYDRINE, PurchaseOriginKind::Jita, self::JITA, 'Jita', 100)],
        ));

        self::assertSame(
            [$this->leg('s30000142>1035000000002', 1.0, [$this->line(self::ZYDRINE, 100, 'ready', 'purchase', 0.01, 1.0)])],
            $this->legsOf($legs),
        );
        self::assertEquals(new HaulPlace(null, null, self::JITA, 'Jita'), $legs->legs[0]->departure);
    }

    public function testPurchaseThatCoversTheWholeQuantityIsPreferredToAStationThatAlsoDoes(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_A, out: 100), $this->station(self::STATION_B, toBring: 100))],
            purchases: [$this->purchase(self::ZYDRINE, PurchaseOriginKind::Jita, self::JITA, 'Jita', 100)],
        ));

        self::assertSame(
            [$this->leg('s30000142>1035000000002', 1.0, [$this->line(self::ZYDRINE, 100, 'ready', 'purchase', 0.01, 1.0)])],
            $this->legsOf($legs),
        );
    }

    public function testPurchaseAndStationSplitTheQuantityAndLegsAreListedPlanStationBeforePurchase(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_A, out: 40), $this->station(self::STATION_B, toBring: 100))],
            purchases: [$this->purchase(self::ZYDRINE, PurchaseOriginKind::Jita, self::JITA, 'Jita', 60)],
        ));

        self::assertSame([
            $this->leg('1035000000001>1035000000002', 0.4, [$this->line(self::ZYDRINE, 40, 'ready', 'project', 0.01, 0.4)]),
            $this->leg('s30000142>1035000000002', 0.6, [$this->line(self::ZYDRINE, 60, 'ready', 'purchase', 0.01, 0.6)]),
        ], $this->legsOf($legs));
    }

    public function testPurchaseAtAHubOtherThanJitaHasAnUnknownAccess(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_A), $this->station(self::STATION_B, toBring: 100))],
            purchases: [$this->purchase(self::ZYDRINE, PurchaseOriginKind::Other, self::OTHER_HUB, 'C-J6MT', 100)],
        ));

        self::assertSame(
            [$this->leg('s30001001>1035000000002', 1.0, [$this->line(self::ZYDRINE, 100, 'ready', 'purchase', 0.01, 1.0)], carriers: [], access: 'unknown')],
            $this->legsOf($legs),
        );
    }

    public function testPurchaseOfUnknownOriginHasNoLegAndIsReportedUnreadableForItsType(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_A), $this->station(self::STATION_B, toBring: 10))],
            purchases: [$this->purchase(self::ZYDRINE, PurchaseOriginKind::Unknown, null, null, 10)],
        ));

        self::assertSame([], $this->legsOf($legs));
        self::assertSame([[self::ZYDRINE, 'Zydrine', null, 'purchase_origin_unknown']], $this->unreadableOf($legs));
    }

    public function testPurchaseWithAnUnknownMissingQuantityHasNoLegAndIsReportedUnreadableForItsType(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_A), $this->station(self::STATION_B, toBring: 10))],
            purchases: [$this->purchase(self::ZYDRINE, PurchaseOriginKind::Jita, 30000142, 'Jita', null)],
        ));

        self::assertSame([], $this->legsOf($legs));
        self::assertSame([[self::ZYDRINE, 'Zydrine', null, 'purchase_delivery_unknown']], $this->unreadableOf($legs));
    }

    public function testQuantityOnTheRoadIsAnInTransitLineOfTheLegBetweenTheRouteEnds(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 10), $this->station(self::STATION_B, toBring: 10, inTransit: 20))],
            legStates: [$this->legState('route-1', 0, self::STATION_B, [$this->transferLine(self::COMPONENT_X, 'Composant X', 30, 30, 10)])],
            routeDepartures: ['route-1' => self::STATION_A],
        ));

        self::assertSame([$this->leg('1035000000001>1035000000002', 300.0, [
            $this->line(self::COMPONENT_X, 10, 'ready', 'project', 10.0, 100.0),
            $this->line(self::COMPONENT_X, 20, 'in_transit', 'transfer', 10.0, 200.0),
        ])], $this->legsOf($legs));
    }

    public function testRouteOfSeveralLegsCountsTheDepartureOfItsFirstLegAndTheArrivalOfItsLastLeg(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A), $this->station(self::STATION_B))],
            legStates: [
                $this->legState('route-2', 1, self::STATION_B, [$this->transferLine(self::COMPONENT_X, 'Composant X', 30, null, 10)]),
                $this->legState('route-2', 0, self::STATION_D, [$this->transferLine(self::COMPONENT_X, 'Composant X', 30, 30, 30)]),
            ],
            routeDepartures: ['route-2' => self::STATION_A],
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 200.0, [$this->line(self::COMPONENT_X, 20, 'in_transit', 'transfer', 10.0, 200.0)])],
            $this->legsOf($legs),
        );
    }

    public function testRouteFullyArrivedAndRouteWithoutArrivalStationAddNoLine(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_A), $this->station(self::STATION_B)), $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A), $this->station(self::STATION_B))],
            legStates: [
                $this->legState('route-3', 0, self::STATION_B, [$this->transferLine(self::ZYDRINE, 'Zydrine', 100, 100, 100)]),
                $this->legState('route-4', 0, null, [$this->transferLine(self::COMPONENT_X, 'Composant X', 30, 30, 10)]),
                $this->legState('route-5', 0, self::STATION_B, [$this->transferLine(self::MORPHITE, 'Morphite', 8, 8, 3)]),
            ],
            routeDepartures: ['route-3' => self::STATION_A, 'route-4' => self::STATION_A, 'route-5' => self::STATION_A],
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 2.5, [$this->line(self::MORPHITE, 5, 'in_transit', 'transfer', 0.5, 2.5)])],
            $this->legsOf($legs),
        );
    }

    public function testUnreadableRouteQuantityIsAnInTransitLineWithoutQuantityNorVolume(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A), $this->station(self::STATION_B, inTransit: null))],
            legStates: [$this->legState('route-1', 0, self::STATION_B, [$this->transferLine(self::COMPONENT_X, 'Composant X', 30, null, null)])],
            routeDepartures: ['route-1' => self::STATION_A],
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', null, [$this->line(self::COMPONENT_X, null, 'in_transit', 'transfer', 10.0, null, reason: 'transfer_unreadable')])],
            $this->legsOf($legs),
        );
    }

    public function testPersonalStockOfTheViewerCoversTheWholeQuantityFromItsStation(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A), $this->station(self::STATION_B, toBring: 40))],
            personalStock: $this->personalStock(self::COMPONENT_X, [[self::STATION_C, 'Jita IV - Moon 4', 'Jita', 50]]),
        ));

        self::assertSame(
            [$this->leg('60003760>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'ready', 'personal_stock', 10.0, 400.0)])],
            $this->legsOf($legs),
        );
    }

    public function testPersonalStockSmallerThanTheNeedGivesWhatItHasAndTheRestHasNoLeg(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A), $this->station(self::STATION_B, toBring: 40))],
            personalStock: $this->personalStock(self::COMPONENT_X, [[self::STATION_C, 'Jita IV - Moon 4', 'Jita', 30]]),
        ));

        self::assertSame(
            [$this->leg('60003760>1035000000002', 300.0, [$this->line(self::COMPONENT_X, 30, 'ready', 'personal_stock', 10.0, 300.0)])],
            $this->legsOf($legs),
        );
    }

    public function testPersonalStockInAHiddenContainerGivesNoDepartureAndItsReasonIsExposed(): void
    {
        $hiddenContainer = new PersonalStockContainerReading('container-1', 9_001, 'Stock', null, self::STATION_C, 'Jita IV - Moon 4', PersonalStockContainerReading::STATUS_UNKNOWN, 'container_in_hidden_division', null);
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 10), $this->station(self::STATION_B, toBring: 40))],
            personalStock: $this->personalStock(self::COMPONENT_X, [[self::STATION_C, 'Jita IV - Moon 4', 'Jita', 50]], [$hiddenContainer]),
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 100.0, [$this->line(self::COMPONENT_X, 10, 'ready', 'project', 10.0, 100.0)])],
            $this->legsOf($legs),
        );
        self::assertSame('container_in_hidden_division', $legs->personalStockUnknownReason);
    }

    public function testPersonalAssetsNotSyncedReasonIsExposedAndTheRestIsStillComputed(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 10), $this->station(self::STATION_B, toBring: 40))],
            personalStockReason: 'personal_assets_not_synced',
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 100.0, [$this->line(self::COMPONENT_X, 10, 'ready', 'project', 10.0, 100.0)])],
            $this->legsOf($legs),
        );
        self::assertSame('personal_assets_not_synced', $legs->personalStockUnknownReason);
    }

    public function testMissingUnitVolumeGivesNullVolumesOnTheLineAndTheLegNeverZero(): void
    {
        $legs = $this->builder->build($this->readings([
            $this->type(self::NOCXIUM, 'Nocxium', $this->station(self::STATION_A, out: 5), $this->station(self::STATION_B, toBring: 5)),
        ]));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', null, [$this->line(self::NOCXIUM, 5, 'ready', 'project', null, null)])],
            $this->legsOf($legs),
        );
    }

    public function testLegWhereNoCharacterIsGrantedAtBothEndsAndNoneIsDeniedHasAnUnknownAccessAndNoCarrier(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 40), $this->station(self::STATION_B, toBring: 40))],
            viewers: [self::CHARACTER_ONE, self::CHARACTER_TWO],
            accessOverrides: [
                self::CHARACTER_ONE => [self::STATION_B => StructureAccessStatus::Denied],
                self::CHARACTER_TWO => [self::STATION_A => StructureAccessStatus::Unknown],
            ],
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'ready', 'project', 10.0, 400.0)], carriers: [], access: 'unknown')],
            $this->legsOf($legs),
        );
    }

    public function testLegDeniedToEveryCharacterAtOneEndIsOmittedWhileOtherLegsStay(): void
    {
        $legs = $this->builder->build($this->readings(
            [
                $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 40), $this->station(self::STATION_B, toBring: 40)),
                $this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_A), $this->station(self::STATION_B, toBring: 100)),
            ],
            purchases: [$this->purchase(self::ZYDRINE, PurchaseOriginKind::Jita, self::JITA, 'Jita', 100)],
            viewers: [self::CHARACTER_ONE, self::CHARACTER_TWO],
            accessOverrides: [
                self::CHARACTER_ONE => [self::STATION_A => StructureAccessStatus::Denied],
                self::CHARACTER_TWO => [self::STATION_B => StructureAccessStatus::Denied],
            ],
        ));

        self::assertSame(
            [$this->leg('s30000142>1035000000002', 1.0, [$this->line(self::ZYDRINE, 100, 'ready', 'purchase', 0.01, 1.0)], carriers: [self::CHARACTER_ONE])],
            $this->legsOf($legs),
        );
    }

    public function testOnlyTheCharactersGrantedAtBothEndsCarryTheLeg(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 40), $this->station(self::STATION_B, toBring: 40))],
            viewers: [self::CHARACTER_ONE, self::CHARACTER_TWO],
            accessOverrides: [self::CHARACTER_TWO => [self::STATION_A => StructureAccessStatus::Denied]],
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'ready', 'project', 10.0, 400.0)], carriers: [self::CHARACTER_ONE])],
            $this->legsOf($legs),
        );
    }

    public function testViewerWithoutAnyCharacterHasNoLeg(): void
    {
        $quantities = [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 40), $this->station(self::STATION_B, toBring: 40))];

        self::assertCount(1, $this->builder->build($this->readings($quantities))->legs);
        self::assertSame([], $this->builder->build($this->readings($quantities, viewers: []))->legs);
    }

    public function testStationThatCannotBeReadHasNoLegForTheTypeAndIsReportedUnreadableWithItsReason(): void
    {
        $legs = $this->builder->build($this->readings([
            $this->type(
                self::COMPONENT_X,
                'Composant X',
                $this->station(self::STATION_A, out: 40),
                $this->station(self::STATION_B, out: null, toBring: null, inTransit: null, reason: 'corporation_assets_not_synced'),
            ),
            $this->type(self::MORPHITE, 'Morphite', $this->station(self::STATION_A, out: 5), $this->station(self::STATION_B, toBring: 5)),
        ]));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 2.5, [$this->line(self::MORPHITE, 5, 'ready', 'project', 0.5, 2.5)])],
            $this->legsOf($legs),
        );
        self::assertSame([[self::COMPONENT_X, 'Composant X', self::STATION_B, 'corporation_assets_not_synced']], $this->unreadableOf($legs));
    }

    public function testUnavailablePlanGivesOneUnreadableEntryPerTypeAndKeepsTheLegsOnTheRoad(): void
    {
        $legs = $this->builder->build($this->readings(
            [
                new TypeStationQuantities(self::COMPONENT_X, 'Composant X', [], null, 'plan_unavailable'),
                new TypeStationQuantities(self::MORPHITE, 'Morphite', [], null, 'plan_unavailable'),
            ],
            legStates: [$this->legState('route-1', 0, self::STATION_B, [$this->transferLine(self::COMPONENT_X, 'Composant X', 30, 30, 10)])],
            routeDepartures: ['route-1' => self::STATION_A],
        ));

        self::assertSame(
            [$this->leg('1035000000001>1035000000002', 200.0, [$this->line(self::COMPONENT_X, 20, 'in_transit', 'transfer', 10.0, 200.0)])],
            $this->legsOf($legs),
        );
        self::assertSame(
            [[self::COMPONENT_X, 'Composant X', null, 'plan_unavailable'], [self::MORPHITE, 'Morphite', null, 'plan_unavailable']],
            $this->unreadableOf($legs),
        );
    }

    public function testStationWhoseSolarSystemIsMissingKeepsItsLegWithNullSystemFields(): void
    {
        $legs = $this->builder->build($this->readings(
            [$this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A), $this->station(self::STATION_B, toBring: 40), $this->station(self::STATION_C, out: 40))],
            places: [self::STATION_C => new HaulPlace(self::STATION_C, 'Jita IV - Moon 4', null, null)],
        ));

        self::assertSame(
            [$this->leg('60003760>1035000000002', 400.0, [$this->line(self::COMPONENT_X, 40, 'ready', 'project', 10.0, 400.0)])],
            $this->legsOf($legs),
        );
        self::assertEquals(new HaulPlace(self::STATION_C, 'Jita IV - Moon 4', null, null), $legs->legs[0]->departure);
    }

    public function testLegsFollowTheArrivalRankInThePlanThenPlanStationsPurchasesAndOtherStations(): void
    {
        $legs = $this->builder->build($this->readings(
            [
                $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, out: 10), $this->station(self::STATION_B, toBring: 10), $this->station(self::STATION_D, toBring: 10), $this->station(self::STATION_C, out: 10)),
                $this->type(self::ZYDRINE, 'Zydrine', $this->station(self::STATION_B, toBring: 5)),
                $this->type(self::MORPHITE, 'Morphite', $this->station(self::STATION_B, toBring: 5), $this->station(self::STATION_C, out: 5)),
            ],
            purchases: [$this->purchase(self::ZYDRINE, PurchaseOriginKind::Jita, self::JITA, 'Jita', 5)],
            planLocationIds: [self::STATION_A, self::STATION_B, self::STATION_D],
        ));

        self::assertSame(
            ['1035000000001>1035000000002', 's30000142>1035000000002', '60003760>1035000000002', '60003760>1035000000003'],
            array_map(static fn (array $leg): string => $leg['key'], $this->legsOf($legs)),
        );
    }

    public function testLinesOfALegAreOrderedByStateThenByTypeName(): void
    {
        $legs = $this->builder->build($this->readings(
            [
                $this->type(self::MORPHITE, 'Morphite', $this->station(self::STATION_A, out: 5), $this->station(self::STATION_B, toBring: 5)),
                $this->type(self::COMPONENT_X, 'Composant X', $this->station(self::STATION_A, inProduction: 10, out: 10), $this->station(self::STATION_B, toBring: 20)),
            ],
            legStates: [$this->legState('route-1', 0, self::STATION_B, [$this->transferLine(self::ZYDRINE, 'Zydrine', 100, 100, 40)])],
            routeDepartures: ['route-1' => self::STATION_A],
        ));

        self::assertSame(
            [
                [self::COMPONENT_X, 'ready'],
                [self::MORPHITE, 'ready'],
                [self::COMPONENT_X, 'in_production'],
                [self::ZYDRINE, 'in_transit'],
            ],
            array_map(static fn (array $line): array => [$line[0], $line[2]], array_merge([], ...array_column($this->legsOf($legs), 'lines'))),
        );
    }

    private function readings(
        array $quantities,
        array $purchases = [],
        array $legStates = [],
        array $routeDepartures = [],
        ?PersonalStock $personalStock = null,
        ?string $personalStockReason = null,
        array $productionEnd = [],
        array $places = [],
        array $viewers = [self::CHARACTER_ONE],
        array $accessOverrides = [],
        array $planLocationIds = [self::STATION_A, self::STATION_B],
    ): HaulReadings {
        $allPlaces = [
            self::STATION_A => new HaulPlace(self::STATION_A, 'Azbel A', 30_000_001, 'Alpha'),
            self::STATION_B => new HaulPlace(self::STATION_B, 'Sotiyo B', 30_000_002, 'Beta'),
            self::STATION_D => new HaulPlace(self::STATION_D, 'Raitaru D', 30_000_003, 'Gamma'),
            self::STATION_C => new HaulPlace(self::STATION_C, 'Jita IV - Moon 4', self::JITA, 'Jita'),
        ];
        $places = $places + $allPlaces;

        $access = [];
        foreach ($viewers as $characterId) {
            foreach (array_keys($places) as $locationId) {
                $access[$characterId][$locationId] = $accessOverrides[$characterId][$locationId] ?? StructureAccessStatus::Granted;
            }
        }

        return new HaulReadings(
            $quantities,
            $purchases,
            $legStates,
            $routeDepartures,
            $personalStock,
            $personalStockReason,
            $productionEnd,
            $places,
            $access,
            $viewers,
            [self::COMPONENT_X => 10.0, self::ZYDRINE => 0.01, self::NOCXIUM => null, self::MORPHITE => 0.5],
            $planLocationIds,
        );
    }

    private function type(int $typeId, string $typeName, StationQuantity ...$stations): TypeStationQuantities
    {
        return new TypeStationQuantities($typeId, $typeName, [], array_values($stations), null);
    }

    private function station(int $locationId, int $inProduction = 0, ?int $out = 0, ?int $toBring = 0, ?int $inTransit = 0, ?string $reason = null): StationQuantity
    {
        return new StationQuantity($locationId, null, 0, 0, $inProduction, 0, $out, $toBring, $inTransit, $reason);
    }

    private function purchase(int $typeId, PurchaseOriginKind $kind, ?int $solarSystemId, ?string $solarSystemName, ?int $missing): PurchaseToDeliver
    {
        return new PurchaseToDeliver('bom-line-'.$typeId, $typeId, $kind, $solarSystemId, $solarSystemName, $missing ?? 1, $missing, PurchaseDeliveryState::Missing);
    }

    private function personalStock(int $typeId, array $locations, array $containers = []): PersonalStock
    {
        $rows = array_map(
            static fn (array $location): array => ['locationId' => $location[0], 'locationName' => $location[1], 'systemName' => $location[2], 'quantity' => $location[3]],
            $locations,
        );
        $total = array_sum(array_column($rows, 'quantity'));

        return new PersonalStock([$typeId => $total], [$typeId => ['total' => $total, 'locations' => $rows]], $containers);
    }

    private function transferLine(int $typeId, string $typeName, int $quantity, ?int $departed, ?int $arrived): StationTransferLineState
    {
        $inTransit = null === $departed || null === $arrived ? null : max(0, $departed - $arrived);

        return new StationTransferLineState($typeId, $typeName, $quantity, null, $departed, $arrived, $inTransit, StationTransferLineState::SOURCE_ASSETS, StationTransferLineState::SOURCE_ASSETS, null);
    }

    private function legState(string $routeId, int $legIndex, ?int $toLocationId, array $lines): TransferLegState
    {
        return new TransferLegState(
            'transfer-'.$routeId.'-'.$legIndex,
            $routeId,
            $legIndex,
            $toLocationId,
            StationTransferState::InTransit,
            $lines,
            null,
            null,
            new \DateTimeImmutable('2026-11-24T00:00:00+00:00'),
        );
    }

    private function legsOf(HaulLegs $legs): array
    {
        return array_map(static fn ($leg): array => [
            'key' => $leg->key,
            'access' => $leg->access->value,
            'carriers' => $leg->carrierCharacterIds,
            'volume' => $leg->volume,
            'lines' => array_map(static fn ($line): array => [
                $line->typeId,
                $line->quantity,
                $line->state->value,
                $line->source->value,
                $line->readyAt?->format(\DATE_ATOM),
                $line->unitVolume,
                $line->volume,
                $line->unknownReason,
            ], $leg->lines),
        ], $legs->legs);
    }

    private function unreadableOf(HaulLegs $legs): array
    {
        return array_map(static fn ($entry): array => [$entry->typeId, $entry->typeName, $entry->locationId, $entry->reason], $legs->unreadable);
    }

    private function leg(string $key, ?float $volume, array $lines, array $carriers = [self::CHARACTER_ONE], string $access = 'granted'): array
    {
        return ['key' => $key, 'access' => $access, 'carriers' => $carriers, 'volume' => $volume, 'lines' => $lines];
    }

    private function line(int $typeId, ?int $quantity, string $state, string $source, ?float $unitVolume, ?float $volume, ?string $readyAt = null, ?string $reason = null): array
    {
        return [$typeId, $quantity, $state, $source, $readyAt, $unitVolume, $volume, $reason];
    }
}
