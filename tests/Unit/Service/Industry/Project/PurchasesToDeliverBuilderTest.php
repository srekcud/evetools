<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedWalletTransaction;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectPurchase;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\Sde\StaStation;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\Courier\PurchaseOriginResolver;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\Service\Industry\Project\PurchaseDeliveryState;
use App\Service\Industry\Project\PurchaseOriginKind;
use App\Service\Industry\Project\PurchasesToDeliver;
use App\Service\Industry\Project\PurchasesToDeliverBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PurchasesToDeliverBuilder::class)]
#[Group('issue-1085')]
final class PurchasesToDeliverBuilderTest extends TestCase
{
    private const int TRITANIUM = 34;
    private const int NOCXIUM = 38;

    private const int JITA = 30_000_142;
    private const int HUB = 30_001_001;
    private const int OTHER_HUB = 30_001_900;
    private const int JITA_STATION = 60_003_760;
    private const int HUB_STATION = 60_001_001;
    private const int OTHER_HUB_STATION = 60_001_900;
    private const int NAMELESS_SYSTEM = 30_009_000;
    private const int NAMELESS_SYSTEM_STATION = 60_009_000;

    private ProjectLineFulfillments $fulfillments;
    private IndustryProject $project;
    private PurchasesToDeliverBuilder $builder;

    protected function setUp(): void
    {
        $this->project = new IndustryProject();
        $this->fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, []);

        $containerFulfillment = $this->createStub(ProjectContainerFulfillment::class);
        $containerFulfillment->method('of')->willReturnCallback(fn (): ProjectLineFulfillments => $this->fulfillments);

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('findBySolarSystemId')->willReturnCallback(static fn (int $solarSystemId): ?MapSolarSystem => match ($solarSystemId) {
            self::JITA => self::solarSystem(self::JITA, 'Jita'),
            self::HUB => self::solarSystem(self::HUB, 'C-J6MT'),
            self::OTHER_HUB => self::solarSystem(self::OTHER_HUB, 'Amarr'),
            default => null,
        });

        $stationRepository = $this->createStub(StaStationRepository::class);
        $stationRepository->method('findByStationId')->willReturnCallback(static fn (int $stationId): ?StaStation => match ($stationId) {
            self::JITA_STATION => self::stationIn(self::JITA_STATION, self::JITA),
            self::HUB_STATION => self::stationIn(self::HUB_STATION, self::HUB),
            self::OTHER_HUB_STATION => self::stationIn(self::OTHER_HUB_STATION, self::OTHER_HUB),
            self::NAMELESS_SYSTEM_STATION => self::stationIn(self::NAMELESS_SYSTEM_STATION, self::NAMELESS_SYSTEM),
            default => null,
        });
        $structureConfigRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureConfigRepository->method('findByLocationId')->willReturn(null);

        $this->builder = new PurchasesToDeliverBuilder(
            $containerFulfillment,
            new PurchaseOriginResolver($stationRepository, $this->createStub(CachedStructureRepository::class), $structureConfigRepository),
            $solarSystemRepository,
        );
    }

    public function testPurchaseOutsideJitaWithNothingInTheContainerIsListedWithItsFullQuantityMissing(): void
    {
        $this->buy(self::TRITANIUM, 1_000, self::HUB_STATION);
        $this->containerHolds([self::TRITANIUM => 0]);

        $result = $this->deliveries();

        self::assertSame([[self::TRITANIUM, PurchaseOriginKind::Other, self::HUB, 'C-J6MT', 1_000, 1_000, PurchaseDeliveryState::Missing]], $this->rowsOf($result));
        self::assertSame([ContainerStockState::Known, 1], [$result->stockState, $result->purchaseCount]);
    }

    public function testPartialDeliveryIsListedWithTheRemainingQuantity(): void
    {
        $this->buy(self::TRITANIUM, 1_000, self::HUB_STATION);
        $this->containerHolds([self::TRITANIUM => 400]);

        self::assertSame([[self::TRITANIUM, PurchaseOriginKind::Other, self::HUB, 'C-J6MT', 1_000, 600, PurchaseDeliveryState::Partial]], $this->rowsOf($this->deliveries()));
    }

    public function testFullyDeliveredPurchaseLeavesTheListButStillCounts(): void
    {
        $this->buy(self::TRITANIUM, 1_000, self::HUB_STATION);
        $this->containerHolds([self::TRITANIUM => 1_500]);

        $result = $this->deliveries();

        self::assertSame([], $result->rows);
        self::assertSame(1, $result->purchaseCount);
    }

    public function testUnreadableContainerStockIsUnknownNeverDeliveredNorMissing(): void
    {
        $this->buy(self::TRITANIUM, 1_000, self::HUB_STATION);
        $this->fulfillments = new ProjectLineFulfillments(ContainerStockState::Unknown);

        $result = $this->deliveries();

        self::assertSame([[self::TRITANIUM, PurchaseOriginKind::Other, self::HUB, 'C-J6MT', 1_000, null, PurchaseDeliveryState::Unknown]], $this->rowsOf($result));
        self::assertSame(ContainerStockState::Unknown, $result->stockState);
    }

    public function testProjectWithoutContainerNameIsUnknownAndReportsTheNoneState(): void
    {
        $this->buy(self::TRITANIUM, 1_000, self::HUB_STATION);
        $this->fulfillments = ProjectLineFulfillments::none();

        $result = $this->deliveries();

        self::assertSame([[self::TRITANIUM, PurchaseOriginKind::Other, self::HUB, 'C-J6MT', 1_000, null, PurchaseDeliveryState::Unknown]], $this->rowsOf($result));
        self::assertSame(ContainerStockState::None, $result->stockState);
    }

    public function testContainerStockIsCreditedToJitaFirstThenToTheOtherOrigin(): void
    {
        $line = $this->buy(self::TRITANIUM, 600, self::JITA_STATION);
        $this->buyOn($line, 400, self::HUB_STATION);
        $this->containerHolds([self::TRITANIUM => 700]);

        self::assertSame(
            [[self::TRITANIUM, PurchaseOriginKind::Other, self::HUB, 'C-J6MT', 400, 300, PurchaseDeliveryState::Partial]],
            $this->rowsOf($this->deliveries()),
        );
    }

    public function testContainerStockIsCreditedToOtherOriginsBeforeTheUnknownOne(): void
    {
        $line = $this->buy(self::TRITANIUM, 600, self::JITA_STATION);
        $this->buyOn($line, 400, null);
        $this->containerHolds([self::TRITANIUM => 500]);

        self::assertSame(
            [
                [self::TRITANIUM, PurchaseOriginKind::Jita, self::JITA, 'Jita', 600, 100, PurchaseDeliveryState::Partial],
                [self::TRITANIUM, PurchaseOriginKind::Unknown, null, null, 400, 400, PurchaseDeliveryState::Missing],
            ],
            $this->rowsOf($this->deliveries()),
        );
    }

    public function testManualPurchaseHasAnUnknownOriginWithoutSystem(): void
    {
        $this->buy(self::NOCXIUM, 50, null);
        $this->containerHolds([]);

        self::assertSame([[self::NOCXIUM, PurchaseOriginKind::Unknown, null, null, 50, 50, PurchaseDeliveryState::Missing]], $this->rowsOf($this->deliveries()));
    }

    public function testRowsAreSortedByJitaThenOtherSystemsByNameThenUnknownAndByTypeIdInAGroup(): void
    {
        $this->buy(self::NOCXIUM, 10, null);
        $this->buy(self::TRITANIUM, 10, self::HUB_STATION);
        $this->buy(self::NOCXIUM, 10, self::OTHER_HUB_STATION);
        $this->buy(self::NOCXIUM, 10, self::JITA_STATION);
        $this->buy(self::TRITANIUM, 10, self::JITA_STATION);
        $this->containerHolds([]);

        self::assertSame(
            [
                [PurchaseOriginKind::Jita, self::TRITANIUM],
                [PurchaseOriginKind::Jita, self::NOCXIUM],
                [PurchaseOriginKind::Other, self::NOCXIUM],
                [PurchaseOriginKind::Other, self::TRITANIUM],
                [PurchaseOriginKind::Unknown, self::NOCXIUM],
            ],
            array_map(static fn ($row): array => [$row->originKind, $row->typeId], $this->deliveries()->rows),
        );
    }

    public function testPurchasesOfQuantityZeroAreIgnored(): void
    {
        $line = $this->buy(self::TRITANIUM, 100, self::HUB_STATION);
        $this->buyOn($line, 0, self::OTHER_HUB_STATION);
        $this->containerHolds([]);

        $result = $this->deliveries();

        self::assertSame([[self::TRITANIUM, PurchaseOriginKind::Other, self::HUB, 'C-J6MT', 100, 100, PurchaseDeliveryState::Missing]], $this->rowsOf($result));
        self::assertSame(1, $result->purchaseCount);
    }

    public function testProjectWithoutPurchasesHasNothingToDeliver(): void
    {
        $this->project->addBomItem((new IndustryProjectBomLine())->setTypeId(self::TRITANIUM)->setTypeName('Tritanium')->setRequiredQuantity(10)->setIsJob(false));

        $result = $this->deliveries();

        self::assertSame([[], 0], [$result->rows, $result->purchaseCount]);
    }

    public function testAStationAbsentFromTheSdeIsAnUnknownOrigin(): void
    {
        $this->buy(self::TRITANIUM, 10, 60_009_999);
        $this->containerHolds([]);

        self::assertSame([[self::TRITANIUM, PurchaseOriginKind::Unknown, null, null, 10, 10, PurchaseDeliveryState::Missing]], $this->rowsOf($this->deliveries()));
    }

    public function testASolarSystemAbsentFromTheSdeNamesKeepsItsIdWithoutName(): void
    {
        $this->buy(self::TRITANIUM, 10, self::NAMELESS_SYSTEM_STATION);
        $this->containerHolds([]);

        self::assertSame([[self::TRITANIUM, PurchaseOriginKind::Other, self::NAMELESS_SYSTEM, null, 10, 10, PurchaseDeliveryState::Missing]], $this->rowsOf($this->deliveries()));
    }

    private function deliveries(): PurchasesToDeliver
    {
        return $this->builder->build($this->project);
    }

    /**
     * @param array<int, int> $quantitiesByTypeId
     */
    private function containerHolds(array $quantitiesByTypeId): void
    {
        $this->fulfillments = new ProjectLineFulfillments(ContainerStockState::Known, $quantitiesByTypeId);
    }

    private function buy(int $typeId, int $quantity, ?int $locationId): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName('Type '.$typeId)->setRequiredQuantity($quantity)->setIsJob(false);
        $this->project->addBomItem($line);

        return $this->buyOn($line, $quantity, $locationId);
    }

    private function buyOn(IndustryProjectBomLine $line, int $quantity, ?int $locationId): IndustryProjectBomLine
    {
        $purchase = (new IndustryProjectPurchase())
            ->setTypeId($line->getTypeId())
            ->setQuantity($quantity)
            ->setUnitPrice(1.0)
            ->setTotalPrice((float) $quantity)
            ->setSource(null === $locationId ? 'manual' : 'esi_wallet');
        if (null !== $locationId) {
            $purchase->setTransaction((new CachedWalletTransaction())->setLocationId($locationId));
        }
        $line->addPurchase($purchase);

        return $line;
    }

    private static function solarSystem(int $solarSystemId, string $name): MapSolarSystem
    {
        return (new MapSolarSystem())->setSolarSystemId($solarSystemId)->setSolarSystemName($name);
    }

    private static function stationIn(int $stationId, int $solarSystemId): StaStation
    {
        return (new StaStation())->setStationId($stationId)->setSolarSystem((new MapSolarSystem())->setSolarSystemId($solarSystemId));
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function rowsOf(PurchasesToDeliver $result): array
    {
        return array_map(
            static fn ($row): array => [$row->typeId, $row->originKind, $row->solarSystemId, $row->solarSystemName, $row->purchasedQuantity, $row->missingQuantity, $row->deliveryState],
            $result->rows,
        );
    }
}
