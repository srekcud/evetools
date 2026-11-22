<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Constant\EveConstants;
use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\ProjectContainerContent;
use App\Service\Industry\Project\ProjectContainerReading;
use App\Service\Industry\Project\ProjectContainerReadings;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\StartedJobConsumption;
use App\Service\Industry\Project\StartedJobConsumptionReader;
use App\Tests\Unit\Service\ESI\BuildsCorporationDirectorAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContainerReadings::class)]
#[Group('issue-1293')]
#[Group('issue-1334')]
final class ProjectContainerReadingsTest extends TestCase
{
    use BuildsCorporationDirectorAccess;

    private const int CORPORATION_ID = 98_129_300;
    private const int STATION_A = 100;
    private const int STATION_B = 200;
    private const int STATION_CONTAINER = 17_366;
    private const int TRITANIUM = 34;
    private const int PYERITE = 35;
    private const int ZYDRINE = 39;
    private const string ISHTAR_A = 'Ishtar A';
    private const string ISHTAR_B = 'Ishtar B';
    private const int STALE_HOURS = 49;

    private array $cachedAssets = [];
    private int $nextItemId = 1_129_300_001_000;
    private array $visibleDivisions = [2];
    private ?\DateTimeImmutable $assetsSyncedAt;
    private bool $directorConnected = true;
    private bool $ownerHasCorporation = true;
    private int $officeAtStationA;
    private int $officeAtStationB;
    private int $itemIdLookups = 0;
    private ProjectContainerReadings $readings;

    protected function setUp(): void
    {
        $this->assetsSyncedAt = new \DateTimeImmutable('-1 hour');

        $cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $cachedAssetRepository->method('hasCorporationAssets')->willReturnCallback(fn (): bool => $this->cachedAssets !== []);
        $cachedAssetRepository->method('lastCachedAtOfCorporationAssets')->willReturnCallback(fn (): ?\DateTimeImmutable => $this->assetsSyncedAt);
        $cachedAssetRepository->method('findByCorporationAndDivisions')->willReturnCallback(
            fn (int $corporationId, array $divisionNumbers): array => array_values(array_filter(
                $this->cachedAssets,
                static fn (CachedAsset $asset): bool => \in_array(
                    $asset->getLocationFlag(),
                    array_map(static fn (int $divisionNumber): string => 'CorpSAG'.$divisionNumber, $divisionNumbers),
                    true,
                ),
            )),
        );
        $cachedAssetRepository->method('findContentsOfContainers')->willReturnCallback(
            fn (int $corporationId, array $containerItemIds): array => $this->contentsOf($containerItemIds),
        );
        $cachedAssetRepository->method('findCorporationByItemIds')->willReturnCallback(
            function (int $corporationId, array $itemIds): array {
                ++$this->itemIdLookups;
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
        $visibilityRepository->method('findByCorporationId')->willReturnCallback(
            fn (): CorpAssetVisibility => (new CorpAssetVisibility())->setCorporationId(self::CORPORATION_ID)->setVisibleDivisions($this->visibleDivisions),
        );

        $consumptionReader = $this->createStub(StartedJobConsumptionReader::class);
        $consumptionReader->method('of')->willReturn(StartedJobConsumption::none());

        $this->officeAtStationA = $this->cacheOffice(self::STATION_A);
        $this->officeAtStationB = $this->cacheOffice(self::STATION_B);

        $visibleAssetsFinder = new VisibleCorporationAssetsFinder($cachedAssetRepository, $visibilityRepository);
        $this->readings = new ProjectContainerReadings(
            new ProjectContainerService(
                $visibleAssetsFinder,
                $this->createStub(IndustryProjectBomLineRepository::class),
                $cachedAssetRepository,
                $visibilityRepository,
                $this->corporationDirectorAccess(),
                $consumptionReader,
            ),
            $visibleAssetsFinder,
            $cachedAssetRepository,
        );
    }

    public function testContainerAtItsStationIsKnownWithItsContentsByType(): void
    {
        $ishtarA = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->cacheItem(self::TRITANIUM, 50, $ishtarA, 'Tritanium');
        $this->cacheItem(self::PYERITE, 10, $ishtarA, 'Pyerite');
        $project = $this->project();
        $container = $project->addContainerAt(self::ISHTAR_A, self::STATION_A);

        $readings = $this->readings->of($project);

        self::assertCount(1, $readings);
        self::assertSame(
            [$container->getId()->toRfc4122(), self::ISHTAR_A, self::STATION_A, ProjectContainerReading::STATUS_KNOWN, null],
            [$readings[0]->containerId, $readings[0]->containerName, $readings[0]->locationId, $readings[0]->status, $readings[0]->unknownReason],
        );
        self::assertSame([[self::TRITANIUM, 'Tritanium', 50], [self::PYERITE, 'Pyerite', 10]], $this->articlesOf($readings[0]));
        self::assertEquals($this->assetsSyncedAt, $readings[0]->assetsSyncedAt);
    }

    public function testEmptyContainerAtItsStationIsKnownWithNoArticle(): void
    {
        $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame([ProjectContainerReading::STATUS_KNOWN, null, []], [$reading->status, $reading->unknownReason, $reading->contents]);
    }

    public function testSubContainerContentsCountButTheSubContainerIsNotAnArticle(): void
    {
        $ishtarA = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $bulk = $this->cacheItem(self::STATION_CONTAINER, 1, $ishtarA, 'Station Container', 'Vrac');
        $this->cacheItem(self::ZYDRINE, 5, $bulk, 'Zydrine');

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(ProjectContainerReading::STATUS_KNOWN, $reading->status);
        self::assertSame([[self::ZYDRINE, 'Zydrine', 5]], $this->articlesOf($reading));
    }

    public function testContainerAtAnotherStationIsUnknownWithoutAnyContents(): void
    {
        $ishtarA = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationB);
        $this->cacheItem(self::TRITANIUM, 50, $ishtarA, 'Tritanium');

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'container_at_another_station', null, self::STATION_A],
            [$reading->status, $reading->unknownReason, $reading->contents, $reading->locationId],
        );
    }

    public function testOnlyTheContentsOfTheAssetAtTheContainerStationAreRead(): void
    {
        $atStationA = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->cacheItem(self::TRITANIUM, 50, $atStationA, 'Tritanium');
        $atStationB = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationB);
        $this->cacheItem(self::PYERITE, 700, $atStationB, 'Pyerite');

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(ProjectContainerReading::STATUS_KNOWN, $reading->status);
        self::assertSame([[self::TRITANIUM, 'Tritanium', 50]], $this->articlesOf($reading));
    }

    public function testSameNamedAssetsOfSeveralVisibleDivisionsAtTheStationAreAddedUp(): void
    {
        $this->visibleDivisions = [2, 3];
        $inDivisionTwo = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA, 'CorpSAG2');
        $this->cacheItem(self::TRITANIUM, 10, $inDivisionTwo, 'Tritanium');
        $inDivisionThree = $this->cacheContainer('  ISHTAR a ', $this->officeAtStationA, 'CorpSAG3');
        $this->cacheItem(self::TRITANIUM, 5, $inDivisionThree, 'Tritanium');

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(ProjectContainerReading::STATUS_KNOWN, $reading->status);
        self::assertSame([[self::TRITANIUM, 'Tritanium', 15]], $this->articlesOf($reading));
    }

    public function testContainerWhoseOfficeIsNotCachedHasAnUnresolvedStation(): void
    {
        $this->cacheContainer(self::ISHTAR_A, 1_129_399_999_999);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'station_unresolved', null],
            [$reading->status, $reading->unknownReason, $reading->contents],
        );
    }

    public function testOfficeStoredInAnItemLeavesTheStationUnresolved(): void
    {
        $wrapper = $this->cacheItem(self::STATION_CONTAINER, 1, $this->officeAtStationA, 'Station Container', 'Wrapper', 'CorpSAG4');
        $officeInsideAnItem = $this->cacheOffice($wrapper, 'item');
        $this->cacheContainer(self::ISHTAR_A, $officeInsideAnItem);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame([ProjectContainerReading::STATUS_UNKNOWN, 'station_unresolved'], [$reading->status, $reading->unknownReason]);
    }

    public function testContainerInAnOfficeOfACorporationStructureIsReadAtTheStructure(): void
    {
        $structure = $this->cacheStructure();
        $office = $this->cacheOffice($structure, 'item');
        $ishtarA = $this->cacheContainer(self::ISHTAR_A, $office);
        $this->cacheItem(self::TRITANIUM, 50, $ishtarA, 'Tritanium');

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, $structure));

        self::assertSame(
            [ProjectContainerReading::STATUS_KNOWN, null, [[self::TRITANIUM, 'Tritanium', 50]]],
            [$reading->status, $reading->unknownReason, $this->articlesOf($reading)],
        );
    }

    public function testContainerInAnOfficeOfACorporationStructureIsNotReadAtAnotherStation(): void
    {
        $structure = $this->cacheStructure();
        $office = $this->cacheOffice($structure, 'item');
        $this->cacheContainer(self::ISHTAR_A, $office);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame([ProjectContainerReading::STATUS_UNKNOWN, 'container_at_another_station'], [$reading->status, $reading->unknownReason]);
    }

    public function testOfficeInAStructureAbsentFromTheCorporationAssetsLeavesTheStationUnresolved(): void
    {
        $office = $this->cacheOffice(1_129_399_999_998, 'item');
        $this->cacheContainer(self::ISHTAR_A, $office);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, 1_129_399_999_998));

        self::assertSame([ProjectContainerReading::STATUS_UNKNOWN, 'station_unresolved'], [$reading->status, $reading->unknownReason]);
    }

    public function testStructureParentsOfOfficesAreReadInOneLookup(): void
    {
        $structure = $this->cacheStructure();
        $this->cacheContainer(self::ISHTAR_A, $this->cacheOffice($structure, 'item'));
        $this->cacheContainer(self::ISHTAR_B, $this->cacheOffice($structure, 'item'));
        $project = $this->project();
        $project->addContainerAt(self::ISHTAR_A, $structure);
        $project->addContainerAt(self::ISHTAR_B, $structure);
        $this->itemIdLookups = 0;

        $this->readings->of($project);

        self::assertLessThanOrEqual(2, $this->itemIdLookups);
    }

    public function testCyclicStorageChainLeavesTheStationUnresolved(): void
    {
        $first = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $second = $this->cacheContainer('Loop', $first);
        $this->relocate($first, $second);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame([ProjectContainerReading::STATUS_UNKNOWN, 'station_unresolved'], [$reading->status, $reading->unknownReason]);
    }

    public function testResolvedAssetAtTheStationWinsOverASameNamedAssetWithAnUnresolvedStation(): void
    {
        $resolved = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->cacheItem(self::TRITANIUM, 50, $resolved, 'Tritanium');
        $unresolved = $this->cacheContainer(self::ISHTAR_A, 1_129_399_999_999);
        $this->cacheItem(self::PYERITE, 700, $unresolved, 'Pyerite');

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(ProjectContainerReading::STATUS_KNOWN, $reading->status);
        self::assertSame([[self::TRITANIUM, 'Tritanium', 50]], $this->articlesOf($reading));
    }

    public function testArticleWithoutATypeNameKeepsANullTypeName(): void
    {
        $ishtarA = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->cacheItem(self::ZYDRINE, 8, $ishtarA, null);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame([[self::ZYDRINE, null, 8]], $this->articlesOf($reading));
    }

    public function testContainerWithoutAnyVisibleAssetOfThatNameIsNotFound(): void
    {
        $this->cacheContainer('Spare', $this->officeAtStationA);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'container_not_found', null],
            [$reading->status, $reading->unknownReason, $reading->contents],
        );
    }

    public function testContainerInADivisionTheOwnerCannotSeeIsReportedAsHidden(): void
    {
        $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA, 'CorpSAG5');
        $this->cacheContainer('Spare', $this->officeAtStationA);

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'container_in_hidden_division', null],
            [$reading->status, $reading->unknownReason, $reading->contents],
        );
    }

    public function testMissingContainerIsReportedStaleWhenTheCorporationAssetsAreOlderThan48Hours(): void
    {
        $this->cacheContainer('Spare', $this->officeAtStationA);
        $this->assetsSyncedAt = new \DateTimeImmutable('-'.self::STALE_HOURS.' hours');

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'corporation_assets_stale', null],
            [$reading->status, $reading->unknownReason, $reading->contents],
        );
    }

    public function testOwnerWithoutCorporationMakesTheReadingUnknown(): void
    {
        $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->ownerHasCorporation = false;

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'owner_without_corporation', null],
            [$reading->status, $reading->unknownReason, $reading->contents],
        );
    }

    public function testCorporationWithoutConnectedDirectorMakesTheReadingUnknown(): void
    {
        $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->directorConnected = false;

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'no_director', null],
            [$reading->status, $reading->unknownReason, $reading->contents],
        );
    }

    public function testCorporationAssetsNeverSynchronizedMakeTheReadingUnknown(): void
    {
        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A, withoutAssets: true));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'corporation_assets_not_synced', null],
            [$reading->status, $reading->unknownReason, $reading->contents],
        );
    }

    public function testOwnerSeeingNoDivisionMakesTheReadingUnknown(): void
    {
        $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->visibleDivisions = [];

        $reading = $this->soleReading($this->projectWithContainerAt(self::ISHTAR_A, self::STATION_A));

        self::assertSame(
            [ProjectContainerReading::STATUS_UNKNOWN, 'no_visible_division', null],
            [$reading->status, $reading->unknownReason, $reading->contents],
        );
    }

    public function testEachContainerOfTheProjectIsReadAtItsOwnStationInNameOrder(): void
    {
        $ishtarA = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->cacheItem(self::TRITANIUM, 50, $ishtarA, 'Tritanium');
        $ishtarB = $this->cacheContainer(self::ISHTAR_B, $this->officeAtStationB);
        $this->cacheItem(self::PYERITE, 12, $ishtarB, 'Pyerite');
        $project = $this->project();
        $project->addContainerAt(self::ISHTAR_B, self::STATION_B);
        $project->addContainerAt(self::ISHTAR_A, self::STATION_A);

        $readings = $this->readings->of($project);

        self::assertSame(
            [
                [self::ISHTAR_A, self::STATION_A, ProjectContainerReading::STATUS_KNOWN, [[self::TRITANIUM, 'Tritanium', 50]]],
                [self::ISHTAR_B, self::STATION_B, ProjectContainerReading::STATUS_KNOWN, [[self::PYERITE, 'Pyerite', 12]]],
            ],
            array_map(fn (ProjectContainerReading $reading): array => [$reading->containerName, $reading->locationId, $reading->status, $this->articlesOf($reading)], $readings),
        );
    }

    public function testContainerWithoutLocationIsReadByNameWhateverTheStation(): void
    {
        $ishtarA = $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);
        $this->cacheItem(self::TRITANIUM, 50, $ishtarA, 'Tritanium');
        $bulk = $this->cacheContainer('Vrac', $this->officeAtStationA);
        $this->cacheItem(self::PYERITE, 3, $bulk, 'Pyerite');
        $project = $this->project();
        $project->addContainerAt(self::ISHTAR_A, self::STATION_A);
        $project->setContainerNames([self::ISHTAR_A, 'Vrac']);

        $readings = $this->readings->of($project);

        self::assertSame(
            [
                [self::ISHTAR_A, self::STATION_A, ProjectContainerReading::STATUS_KNOWN, [[self::TRITANIUM, 'Tritanium', 50]]],
                ['Vrac', null, ProjectContainerReading::STATUS_KNOWN, [[self::PYERITE, 'Pyerite', 3]]],
            ],
            array_map(fn (ProjectContainerReading $reading): array => [$reading->containerName, $reading->locationId, $reading->status, $this->articlesOf($reading)], $readings),
        );
    }

    public function testProjectWithoutContainerHasNoReading(): void
    {
        $this->cacheContainer(self::ISHTAR_A, $this->officeAtStationA);

        self::assertSame([], $this->readings->of($this->project()));
    }

    private function project(): IndustryProject
    {
        $owner = $this->createStub(User::class);
        $owner->method('getCorporationId')->willReturnCallback(fn (): ?int => $this->ownerHasCorporation ? self::CORPORATION_ID : null);
        if ($this->directorConnected) {
            $this->corporationMember(self::CORPORATION_ID, ['Director']);
        }

        return (new IndustryProject())->setOwner($owner);
    }

    private function projectWithContainerAt(string $name, int $locationId, bool $withoutAssets = false): IndustryProject
    {
        if ($withoutAssets) {
            $this->cachedAssets = [];
        }
        $project = $this->project();
        $project->addContainerAt($name, $locationId);

        return $project;
    }

    private function soleReading(IndustryProject $project): ProjectContainerReading
    {
        $readings = $this->readings->of($project);
        self::assertCount(1, $readings);

        return $readings[0];
    }

    private function articlesOf(ProjectContainerReading $reading): array
    {
        $articles = array_map(
            static fn (ProjectContainerContent $content): array => [$content->typeId, $content->typeName, $content->quantity],
            $reading->contents ?? [],
        );
        usort($articles, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $articles;
    }

    private function cacheOffice(int $locationId, string $locationType = 'station'): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = $this->asset($itemId, 27, 1, $locationId, $locationType, EveConstants::OFFICE_LOCATION_FLAG, null, null);

        return $itemId;
    }

    private function cacheStructure(): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = $this->asset($itemId, 35_825, 1, 30_000_142, 'solar_system', 'AutoFit', null, null);

        return $itemId;
    }

    private function cacheContainer(string $name, int $locationId, string $locationFlag = 'CorpSAG2'): int
    {
        return $this->cacheItem(self::STATION_CONTAINER, 1, $locationId, 'Station Container', $name, $locationFlag);
    }

    private function cacheItem(int $typeId, int $quantity, int $locationId, ?string $typeName, ?string $itemName = null, string $locationFlag = 'Unlocked'): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = $this->asset($itemId, $typeId, $quantity, $locationId, 'item', $locationFlag, $itemName, $typeName);

        return $itemId;
    }

    private function relocate(int $itemId, int $locationId): void
    {
        foreach ($this->cachedAssets as $asset) {
            if ($asset->getItemId() === $itemId) {
                $asset->setLocationId($locationId);
            }
        }
    }

    private function asset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType, string $locationFlag, ?string $itemName, ?string $typeName): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
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
