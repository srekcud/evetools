<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project\Haul;

use App\Entity\Character;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\IndustryProjectJobMatch;
use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\MapSolarSystem;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Enum\StructureAccessStatus;
use App\Repository\CachedAssetRepository;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectStationTransferRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\ContainerStockState;
use App\Service\Industry\Project\Courier\PurchaseOriginResolver;
use App\Service\Industry\Project\Haul\HaulLegs;
use App\Service\Industry\Project\Haul\HaulLegsBuilder;
use App\Service\Industry\Project\Haul\ProjectHaulLegs;
use App\Service\Industry\Project\ProjectContainerFulfillment;
use App\Service\Industry\Project\ProjectContainerReadings;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\ProjectContainerStations;
use App\Service\Industry\Project\ProjectLineFulfillments;
use App\Service\Industry\Project\PurchasesToDeliverBuilder;
use App\Service\Industry\Project\StationQuantitiesSource;
use App\Service\Industry\Project\StationQuantity;
use App\Service\Industry\Project\StructureAccess\CharacterStructureAccess;
use App\Service\Industry\Project\Transfer\ProjectStationTransfers;
use App\Service\Industry\Project\Transfer\TransferLegStateCalculator;
use App\Service\Industry\Project\TypeStationQuantities;
use App\Tests\Unit\Industry\Application\Double\InMemoryItemVolumes;
use App\Tests\Unit\Support\StubsPersonalStockReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectHaulLegs::class)]
#[Group('issue-1364')]
final class ProjectHaulLegsTest extends TestCase
{
    use StubsPersonalStockReader;

    private const int STATION_A = 1_035_000_000_001;
    private const int STATION_B = 1_035_000_000_002;
    private const int COMPONENT_X = 21_001;
    private const int CHARACTER_ONE = 2_112_000_001;

    private IndustryProject $project;
    private User $viewer;
    private IndustryStructureConfig $structureA;
    private IndustryStructureConfig $structureB;

    protected function setUp(): void
    {
        $this->viewer = (new User())->addCharacter((new Character())->setEveCharacterId(self::CHARACTER_ONE));
        $this->project = (new IndustryProject())->setOwner($this->viewer)->setName('Ishtar')->setStatus(ProjectStatus::InProgress);
        $this->structureA = (new IndustryStructureConfig())->setName('Azbel A')->setLocationId(self::STATION_A)->setSolarSystemId(30_000_001);
        $this->structureB = (new IndustryStructureConfig())->setName('Sotiyo B')->setLocationId(self::STATION_B)->setSolarSystemId(30_000_002);
    }

    public function testCompletedProjectHasNoLegNoUnreadableEntryAndNeverReadsPersonalAssets(): void
    {
        $this->project->setStatus(ProjectStatus::Completed);
        $assetRepository = $this->createMock(CachedAssetRepository::class);
        $assetRepository->expects(self::never())->method('hasPersonalAssetsOf');
        $assetRepository->expects(self::never())->method('getAggregatedQuantitiesByUser');
        $assetRepository->expects(self::never())->method('getQuantitiesByUserWithLocations');

        $result = $this->reader($assetRepository)->of($this->project, $this->viewer);

        self::assertSame([], $result->legs);
        self::assertSame([], $result->unreadable);
        self::assertNull($result->personalStockUnknownReason);
    }

    public function testViewerWithoutSyncedPersonalAssetsGetsTheNotSyncedReasonAndTheStockIsNotRead(): void
    {
        $assetRepository = $this->createMock(CachedAssetRepository::class);
        $assetRepository->method('hasPersonalAssetsOf')->willReturn(false);
        $assetRepository->expects(self::never())->method('getAggregatedQuantitiesByUser');
        $assetRepository->expects(self::never())->method('getQuantitiesByUserWithLocations');

        $result = $this->reader($assetRepository)->of($this->project, $this->viewer);

        self::assertSame('personal_assets_not_synced', $result->personalStockUnknownReason);
    }

    public function testProductionEndOfAStationIsTheLatestEndDateOfTheActivePausedAndReadyMatchesOfItsJobLines(): void
    {
        $this->addJobLinePlannedAt($this->structureA, [
            ['active', '2026-11-23T10:00:00+00:00'],
            ['ready', '2026-11-23T14:00:00+00:00'],
            ['paused', '2026-11-23T12:00:00+00:00'],
            ['delivered', '2026-11-25T09:00:00+00:00'],
        ]);

        $result = $this->reader($this->syncedAssets())->of($this->project, $this->viewer);

        self::assertSame(
            [['1035000000001>1035000000002', [[self::COMPONENT_X, 40, 'in_production', '2026-11-23T14:00:00+00:00']]]],
            $this->legsOf($result),
        );
    }

    public function testProductionEndIsUnknownWhenOneOfTheMatchesOfTheStationHasNoEndDate(): void
    {
        $this->addJobLinePlannedAt($this->structureA, [['active', '2026-11-23T10:00:00+00:00'], ['active', null]]);

        $result = $this->reader($this->syncedAssets())->of($this->project, $this->viewer);

        self::assertSame(
            [['1035000000001>1035000000002', [[self::COMPONENT_X, 40, 'in_production', null]]]],
            $this->legsOf($result),
        );
    }

    public function testProductionEndIgnoresTheJobLinesPlannedAtAnotherStation(): void
    {
        $this->addJobLinePlannedAt($this->structureB, [['active', '2026-11-30T10:00:00+00:00']]);
        $this->addJobLinePlannedAt($this->structureA, [['active', '2026-11-23T10:00:00+00:00']]);

        $result = $this->reader($this->syncedAssets())->of($this->project, $this->viewer);

        self::assertSame(
            [['1035000000001>1035000000002', [[self::COMPONENT_X, 40, 'in_production', '2026-11-23T10:00:00+00:00']]]],
            $this->legsOf($result),
        );
    }

    private function syncedAssets(): CachedAssetRepository
    {
        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('hasPersonalAssetsOf')->willReturn(true);
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturn([]);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturn([]);

        return $assetRepository;
    }

    private function addJobLinePlannedAt(IndustryStructureConfig $structure, array $matches): void
    {
        $line = (new IndustryProjectBomLine())
            ->setTypeId(self::COMPONENT_X)
            ->setTypeName('Composant X')
            ->setIsJob(true)
            ->setJobGroup('component')
            ->setActivityType(IndustryProjectBomLine::ACTIVITY_MANUFACTURING)
            ->setPlannedStructureId($structure->getId()->toRfc4122());
        foreach ($matches as $index => [$status, $endDate]) {
            $line->addJobMatch(
                (new IndustryProjectJobMatch())
                    ->setEsiJobId(900_000 + $index)
                    ->setRuns(1)
                    ->setStatus($status)
                    ->setEndDate(null === $endDate ? null : new \DateTimeImmutable($endDate)),
            );
        }
        $this->project->addBomItem($line);
    }

    private function reader(CachedAssetRepository $assetRepository): ProjectHaulLegs
    {
        $structureRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureRepository->method('findByUser')->willReturn([$this->structureA, $this->structureB]);
        $structureRepository->method('findByLocationId')->willReturn(null);

        $bomLineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomLineRepository->method('findPlannedStructureIdsByProject')->willReturn([
            $this->structureA->getId()->toRfc4122(),
            $this->structureB->getId()->toRfc4122(),
        ]);

        $stationQuantities = $this->createStub(StationQuantitiesSource::class);
        $stationQuantities->method('of')->willReturn([
            new TypeStationQuantities(self::COMPONENT_X, 'Composant X', [], [
                new StationQuantity(self::STATION_A, 'Azbel A', 40, 0, 40, 0, 0, 0, 0, null),
                new StationQuantity(self::STATION_B, 'Sotiyo B', 40, 0, 0, 0, 0, 40, 0, null),
            ], null),
        ]);

        $containerFulfillment = $this->createStub(ProjectContainerFulfillment::class);
        $containerFulfillment->method('of')->willReturn(new ProjectLineFulfillments(ContainerStockState::Known, []));

        $solarSystemRepository = $this->createStub(MapSolarSystemRepository::class);
        $solarSystemRepository->method('findBySolarSystemId')->willReturnCallback(static fn (int $solarSystemId): ?MapSolarSystem => match ($solarSystemId) {
            30_000_001 => (new MapSolarSystem())->setSolarSystemId(30_000_001)->setSolarSystemName('Alpha'),
            30_000_002 => (new MapSolarSystem())->setSolarSystemId(30_000_002)->setSolarSystemName('Beta'),
            default => null,
        });

        $originResolver = new PurchaseOriginResolver(
            $this->createStub(StaStationRepository::class),
            $this->createStub(CachedStructureRepository::class),
            $structureRepository,
        );

        $transferRepository = $this->createStub(IndustryProjectStationTransferRepository::class);
        $transferRepository->method('findByProject')->willReturn([]);

        $structureAccess = $this->createStub(CharacterStructureAccess::class);
        $structureAccess->method('statusOf')->willReturn(StructureAccessStatus::Granted);

        return new ProjectHaulLegs(
            $stationQuantities,
            new PurchasesToDeliverBuilder($containerFulfillment, $originResolver, $solarSystemRepository),
            new ProjectStationTransfers($transferRepository, new TransferLegStateCalculator(), new InMemoryItemVolumes([self::COMPONENT_X => 10.0])),
            new ProjectContainerReadings($this->createStub(ProjectContainerService::class), $this->createStub(VisibleCorporationAssetsFinder::class), $assetRepository),
            new ProjectContainerStations($structureRepository, $bomLineRepository),
            $this->personalStockReaderOn($assetRepository),
            $assetRepository,
            $structureAccess,
            new InMemoryItemVolumes([self::COMPONENT_X => 10.0]),
            $originResolver,
            $solarSystemRepository,
            $structureRepository,
            new HaulLegsBuilder(),
        );
    }

    private function legsOf(HaulLegs $result): array
    {
        return array_map(static fn ($leg): array => [
            $leg->key,
            array_map(static fn ($line): array => [$line->typeId, $line->quantity, $line->state->value, $line->readyAt?->format(\DATE_ATOM)], $leg->lines),
        ], $result->legs);
    }
}
