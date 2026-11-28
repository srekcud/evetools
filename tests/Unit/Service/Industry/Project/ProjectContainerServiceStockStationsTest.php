<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\ApiResource\Industry\Project\ProjectContainerVerificationResource;
use App\Constant\EveConstants;
use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\StartedJobConsumption;
use App\Service\Industry\Project\StartedJobConsumptionReader;
use App\Tests\Unit\Service\ESI\BuildsCorporationDirectorAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ProjectContainerService::class)]
#[Group('issue-1476')]
final class ProjectContainerServiceStockStationsTest extends TestCase
{
    use BuildsCorporationDirectorAccess;

    private const int CORPORATION_ID = 98_147_600;
    private const int STATION_A = 100;
    private const int STATION_B = 200;
    private const string STATION_A_NAME = 'Amarr VIII (Oris) - Emperor Family Academy';
    private const string STATION_B_NAME = 'Jita IV - Moon 4 - Caldari Navy Assembly Plant';
    private const string CONTAINER_NAME = 'Ishtar';
    private const int STATION_CONTAINER = 17_366;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int TRITANIUM_REQUIRED = 100;

    private array $cachedAssets = [];
    private int $nextItemId = 1_147_600_001_000;
    private int $officeAtStationA;
    private int $officeAtStationB;
    private ProjectContainerService $service;

    protected function setUp(): void
    {
        $cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $cachedAssetRepository->method('hasCorporationAssets')->willReturnCallback(fn (): bool => $this->cachedAssets !== []);
        $cachedAssetRepository->method('lastCachedAtOfCorporationAssets')->willReturn(new \DateTimeImmutable('-1 hour'));
        $cachedAssetRepository->method('findByCorporationAndDivisions')->willReturnCallback(
            fn (int $corporationId, array $divisionNumbers): array => array_values(array_filter(
                $this->cachedAssets,
                static fn (CachedAsset $asset): bool => \in_array($asset->getLocationFlag(), array_map(static fn (int $division): string => 'CorpSAG'.$division, $divisionNumbers), true),
            )),
        );
        $cachedAssetRepository->method('findContentsOfContainers')->willReturnCallback(
            fn (int $corporationId, array $containerItemIds): array => $this->contentsOf($containerItemIds),
        );
        $cachedAssetRepository->method('findCorporationByItemIds')->willReturnCallback(
            function (int $corporationId, array $itemIds): array {
                $found = [];
                foreach ($this->cachedAssets as $asset) {
                    if (\in_array($asset->getItemId(), $itemIds, true)) {
                        $found[$asset->getItemId()] = $asset;
                    }
                }

                return $found;
            },
        );

        $visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $visibilityRepository->method('findByCorporationId')->willReturn((new CorpAssetVisibility())->setCorporationId(self::CORPORATION_ID)->setVisibleDivisions([2]));

        $bomLineRepository = $this->createStub(IndustryProjectBomLineRepository::class);
        $bomLineRepository->method('findMaterialsByProject')->willReturnCallback(fn (): array => [$this->materialLine(self::TRITANIUM, self::TRITANIUM_REQUIRED)]);
        $bomLineRepository->method('findComponentJobsByProject')->willReturn([]);

        $consumptionReader = $this->createStub(StartedJobConsumptionReader::class);
        $consumptionReader->method('of')->willReturn(StartedJobConsumption::none());

        $this->corporationMember(self::CORPORATION_ID, ['Director']);
        $this->officeAtStationA = $this->cacheOffice(self::STATION_A, self::STATION_A_NAME);
        $this->officeAtStationB = $this->cacheOffice(self::STATION_B, self::STATION_B_NAME);

        $this->service = new ProjectContainerService(
            new VisibleCorporationAssetsFinder($cachedAssetRepository, $visibilityRepository),
            $bomLineRepository,
            $cachedAssetRepository,
            $visibilityRepository,
            $this->corporationDirectorAccess(),
            $consumptionReader,
        );
    }

    public function testStockOfTheSameNamedContainerAtAnotherStationIsStillCountedButReportedAtThatStation(): void
    {
        $this->cacheContainer(self::CONTAINER_NAME, $this->officeAtStationA);
        $this->store($this->cacheContainer(self::CONTAINER_NAME, $this->officeAtStationB), self::TRITANIUM, 100);

        $line = $this->soleLine();

        self::assertSame([100, 'verified'], [$line->containerQuantity, $line->status]);
        self::assertSame([[self::STATION_B, self::STATION_B_NAME, 100]], $this->stockOf($line));
    }

    public function testStockIsListedStationByStationInStationNameOrder(): void
    {
        $this->store($this->cacheContainer(self::CONTAINER_NAME, $this->officeAtStationB), self::TRITANIUM, 40);
        $this->store($this->cacheContainer(self::CONTAINER_NAME, $this->officeAtStationA), self::TRITANIUM, 60);

        $line = $this->soleLine();

        self::assertSame(100, $line->containerQuantity);
        self::assertSame([[self::STATION_A, self::STATION_A_NAME, 60], [self::STATION_B, self::STATION_B_NAME, 40]], $this->stockOf($line));
    }

    public function testContentsOfASubContainerAreReportedAtTheStationOfTheNamedContainer(): void
    {
        $named = $this->cacheContainer(self::CONTAINER_NAME, $this->officeAtStationB);
        $this->store($this->cacheItem(self::STATION_CONTAINER, 1, $named, 'Unlocked', null), self::TRITANIUM, 30);
        $this->store($named, self::TRITANIUM, 20);

        self::assertSame([[self::STATION_B, self::STATION_B_NAME, 50]], $this->stockOf($this->soleLine()));
    }

    public function testStockOfAContainerWhoseStationCannotBeResolvedIsReportedWithoutStation(): void
    {
        $orphanOffice = $this->nextItemId++;
        $this->store($this->cacheContainer(self::CONTAINER_NAME, $orphanOffice), self::TRITANIUM, 70);

        self::assertSame([[null, null, 70]], $this->stockOf($this->soleLine()));
    }

    public function testMaterialAbsentFromEveryContainerHasNoStockStation(): void
    {
        $this->store($this->cacheContainer(self::CONTAINER_NAME, $this->officeAtStationA), self::PYERITE, 500);

        $line = $this->soleLine();

        self::assertSame(['missing', []], [$line->status, $line->stockStations]);
    }

    public function testStockStationsAreUnknownWhenTheContainerCannotBeRead(): void
    {
        $this->cacheContainer('Another box', $this->officeAtStationA);

        $line = $this->soleLine();

        self::assertSame(['container_not_found', null], [$line->status, $line->stockStations]);
    }

    private function soleLine(): ProjectContainerVerificationResource
    {
        $project = (new IndustryProject())->setOwner($this->ownerOfCorporation())->setContainerNames([self::CONTAINER_NAME]);
        $lines = $this->service->verifyContainer($project);
        self::assertCount(1, $lines);

        return $lines[0];
    }

    /**
     * @return list<array{0: ?int, 1: ?string, 2: int}>
     */
    private function stockOf(ProjectContainerVerificationResource $line): array
    {
        self::assertNotNull($line->stockStations);

        return array_map(static fn (array $stock): array => [$stock['locationId'], $stock['stationName'], $stock['quantity']], $line->stockStations);
    }

    private function ownerOfCorporation(): User
    {
        $owner = $this->createStub(User::class);
        $owner->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        return $owner;
    }

    private function materialLine(int $typeId, int $requiredQuantity): IndustryProjectBomLine
    {
        $line = (new IndustryProjectBomLine())->setTypeId($typeId)->setTypeName('Tritanium')->setRequiredQuantity($requiredQuantity)->setIsJob(false);
        (new \ReflectionProperty(IndustryProjectBomLine::class, 'id'))->setValue($line, Uuid::v4());

        return $line;
    }

    private function cacheOffice(int $locationId, string $stationName): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = $this->asset($itemId, 27, 1, $locationId, 'station', EveConstants::OFFICE_LOCATION_FLAG, null, $stationName);

        return $itemId;
    }

    private function cacheContainer(string $name, int $officeItemId): int
    {
        return $this->cacheItem(self::STATION_CONTAINER, 1, $officeItemId, 'CorpSAG2', $name);
    }

    private function store(int $containerItemId, int $typeId, int $quantity): void
    {
        $this->cacheItem($typeId, $quantity, $containerItemId, 'Unlocked', null);
    }

    private function cacheItem(int $typeId, int $quantity, int $locationId, string $locationFlag, ?string $itemName): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = $this->asset($itemId, $typeId, $quantity, $locationId, 'item', $locationFlag, $itemName, 'Somewhere');

        return $itemId;
    }

    private function asset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType, string $locationFlag, ?string $itemName, string $locationName): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName('Item')
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName($locationName)
            ->setLocationType($locationType)
            ->setLocationFlag($locationFlag)
            ->setItemName($itemName)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
    }

    private function contentsOf(array $containerItemIds): array
    {
        $contents = [];
        $seenItemIds = [];
        while ($containerItemIds !== []) {
            $level = array_values(array_filter(
                $this->cachedAssets,
                static fn (CachedAsset $asset): bool => \in_array($asset->getLocationId(), $containerItemIds, true) && !isset($seenItemIds[$asset->getItemId()]),
            ));
            foreach ($level as $asset) {
                $seenItemIds[$asset->getItemId()] = true;
            }
            $contents = [...$contents, ...$level];
            $containerItemIds = array_map(static fn (CachedAsset $asset): int => $asset->getItemId(), $level);
        }

        return $contents;
    }
}
