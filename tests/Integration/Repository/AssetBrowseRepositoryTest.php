<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Dto\Assets\AssetScope;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\Sde\InvCategory;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Repository\AssetBrowseRepository;
use App\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('issue-878')]
final class AssetBrowseRepositoryTest extends IntegrationTestCase
{
    private const int JITA_ID = 60_003_760;
    private const int AMARR_ID = 60_008_494;
    private const int CORPORATION_ID = 98_878_001;
    private const int OTHER_CORPORATION_ID = 98_878_002;

    private const int STOCK_STRUCTURE_ID = 1_030_000_000_123;
    private const int VELDSPAR_TYPE = 1_230;
    private const int STOCK_LOOSE_TRITANIUM = 1_395_000_001;
    private const int STOCK_JITA_BOX = 1_395_000_002;
    private const int STOCK_PYERITE_IN_JITA_BOX = 1_395_000_003;
    private const int STOCK_JITA_INNER_BOX = 1_395_000_004;
    private const int STOCK_TRITANIUM_IN_INNER_BOX = 1_395_000_005;
    private const int STOCK_AMARR_BOX = 1_395_000_006;
    private const int STOCK_TRITANIUM_IN_AMARR_BOX = 1_395_000_007;
    private const int STOCK_LOOSE_PYERITE_AT_AMARR = 1_395_000_008;
    private const int STOCK_STRUCTURE_BOX = 1_395_000_009;
    private const int STOCK_TRITANIUM_IN_STRUCTURE_BOX = 1_395_000_010;
    private const int STOCK_VELDSPAR = 1_395_000_011;
    private const int STOCK_OTHER_CHARACTER_TRITANIUM = 1_395_000_012;
    private const int STOCK_CORPORATION_TRITANIUM = 1_395_000_013;

    private const int UNRESOLVED_STRUCTURE_ID = 1_020_000_000_001;
    private const int UNRESOLVED_LOCATION_ID = 1_020_000_000_002;
    private const int NAMED_OTHER_STRUCTURE_ID = 1_020_000_000_003;
    private const int SOLAR_SYSTEM_ID = 30_000_142;
    private const int CUSTOMS_ROBOTICS_LOOSE = 1_390_000_001;
    private const int CUSTOMS_BOX = 1_390_000_002;
    private const int CUSTOMS_ROBOTICS_IN_BOX = 1_390_000_003;
    private const int CUSTOMS_COOLANT_AT_LOCATION = 1_390_000_004;
    private const int CUSTOMS_ROBOTICS_IN_STATION = 1_390_000_005;
    private const int CUSTOMS_STATION_BOX = 1_390_000_006;
    private const int CUSTOMS_BACTERIA_IN_STATION = 1_390_000_007;
    private const int CUSTOMS_BACTERIA_IN_NAMED_STRUCTURE = 1_390_000_008;
    private const int CUSTOMS_BACTERIA_IN_SOLAR_SYSTEM = 1_390_000_009;
    private const int CUSTOMS_OTHER_CHARACTER_ROBOTICS = 1_390_000_010;
    private const int CUSTOMS_CORPORATION_ROBOTICS = 1_390_000_011;
    private const int CUSTOMS_ROBOTICS_IN_NAMED_STRUCTURE = 1_390_000_012;
    private const int CUSTOMS_ROBOTICS_IN_SOLAR_SYSTEM = 1_390_000_013;

    private const int ROBOTICS_TYPE = 9_848;
    private const int COOLANT_TYPE = 9_832;
    private const int BACTERIA_TYPE = 2_393;

    private const int SHIP_TYPE = 603;
    private const int TRITANIUM_TYPE = 34;
    private const int PYERITE_TYPE = 35;
    private const int CONTAINER_TYPE = 17_366;

    private const int SHIP = 1_878_000_001;
    private const int CARGO_OF_SHIP = 1_878_000_002;
    private const int BOX = 1_878_000_003;
    private const int TRITANIUM_IN_BOX = 1_878_000_004;
    private const int INNER_BOX = 1_878_000_005;
    private const int PYERITE_IN_INNER_BOX = 1_878_000_006;
    private const int LOOSE_TRITANIUM = 1_878_000_007;
    private const int AMARR_TRITANIUM = 1_878_000_008;
    private const int ORPHAN_SHIP = 1_878_000_009;

    private const int CORP_BOX = 1_878_000_011;
    private const int CORP_TRITANIUM_IN_BOX = 1_878_000_012;
    private const int CORP_LOOSE_TRITANIUM = 1_878_000_013;
    private const int HIDDEN_BOX = 1_878_000_014;
    private const int HIDDEN_VELDSPAR_IN_BOX = 1_878_000_015;
    private const int AMARR_CORP_TRITANIUM = 1_878_000_016;
    private const int OTHER_CORP_BOX = 1_878_000_017;

    private AssetBrowseRepository $repository;
    private string $stockOtherCharacterId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new AssetBrowseRepository($this->em->getConnection());
        $this->persistSde();
    }

    public function testPersonalSummaryCountsContentsDownTheContainerChainAndBuildsTheTotals(): void
    {
        $scope = $this->personalScopeWithInventory();

        $locations = $this->repository->summarizeLocations($scope);

        self::assertSame(['Jita IV', 'Amarr VIII', 'Unknown structure'], array_column($locations, 'locationName'));
        $jita = $locations[0];
        self::assertSame(self::JITA_ID, $jita['locationId']);
        self::assertSame('station', $jita['locationType']);
        self::assertSame('Jita', $jita['solarSystemName']);
        self::assertSame(1 + 300 + 1 + 700 + 1 + 50 + 1_000, $jita['itemCount']);
        self::assertSame(1, $jita['shipCount']);
        self::assertSame([], $jita['divisions']);
        self::assertSame(5, $locations[1]['itemCount']);
        self::assertSame(
            ['itemCount' => 2_059, 'typeCount' => 4, 'systemCount' => 2, 'locationCount' => 3, 'lastSyncedAt' => max(array_column($locations, 'cachedAt'))],
            $this->repository->totals($scope),
        );
    }

    public function testAnItemWhoseParentIsNotCachedIsARootOfItsOwnLocationAndOtherOwnersAreIgnored(): void
    {
        $scope = $this->personalScopeWithInventory();

        $orphan = $this->repository->rootItemsOf($scope, 999_000_999);

        self::assertCount(1, $orphan);
        self::assertSame(self::ORPHAN_SHIP, $orphan[0]['itemId']);
        self::assertSame(1, $this->repository->summarizeLocations($scope)[2]['shipCount']);
        self::assertSame([], $this->repository->rootItemsOf($scope, 77_777));
    }

    public function testRootItemsOfALocationAreSortedByTypeNameAndCarryTheirDirectChildCount(): void
    {
        $scope = $this->personalScopeWithInventory();

        $items = $this->repository->rootItemsOf($scope, self::JITA_ID);

        self::assertSame(
            [self::BOX, self::SHIP, self::LOOSE_TRITANIUM],
            array_column($items, 'itemId'),
        );
        self::assertSame([2, 1, 0], array_column($items, 'childCount'));
        self::assertSame(self::CONTAINER_TYPE, $items[0]['typeId']);
        self::assertSame(2, $items[0]['categoryId']);
        self::assertSame(12, $items[0]['groupId']);
        self::assertSame(6, $items[1]['categoryId']);
        self::assertSame('Merlin', $items[1]['typeName']);
        self::assertSame('Jita IV', $items[1]['locationName']);
        self::assertSame('Hangar', $items[1]['locationFlag']);
        self::assertTrue($items[1]['isSingleton']);
    }

    public function testContainerContentsAreTheDirectChildrenOnlyAndAnUnknownContainerIsNull(): void
    {
        $scope = $this->personalScopeWithInventory();

        $contents = $this->repository->contentsOf($scope, self::BOX);

        self::assertSame([self::INNER_BOX, self::TRITANIUM_IN_BOX], array_column($contents, 'itemId'));
        self::assertSame([1, 0], array_column($contents, 'childCount'));
        self::assertSame([self::PYERITE_IN_INNER_BOX], array_column($this->repository->contentsOf($scope, self::INNER_BOX), 'itemId'));
        self::assertNull($this->repository->contentsOf($scope, 1_999_999_999));
    }

    public function testCorporationSummaryListsVisibleDivisionsOnlyWithTheirContentAndFiltersByDivisionName(): void
    {
        $scope = $this->corporationScopeWithBoxes([1, 2]);

        $locations = $this->repository->summarizeLocations($scope);

        self::assertSame(['Jita IV', 'Amarr VIII'], array_column($locations, 'locationName'));
        self::assertSame(1 + 40 + 10, $locations[0]['itemCount']);
        self::assertSame([['name' => 'Reactions', 'count' => 51]], $locations[0]['divisions']);
        self::assertSame([['name' => 'Minerals', 'count' => 7]], $locations[1]['divisions']);
        self::assertSame(58, $this->repository->totals($scope)['itemCount']);

        $onlyMinerals = $this->repository->summarizeLocations(AssetScope::forCorporation(self::CORPORATION_ID, [1, 2], 'Minerals'));

        self::assertSame(['Amarr VIII'], array_column($onlyMinerals, 'locationName'));
    }

    public function testCorporationContentsOfAHiddenDivisionAreUnreachableEvenByItemId(): void
    {
        $scope = $this->corporationScopeWithBoxes([1, 2]);

        self::assertSame([self::CORP_TRITANIUM_IN_BOX], array_column($this->repository->contentsOf($scope, self::CORP_BOX), 'itemId'));
        self::assertNull($this->repository->contentsOf($scope, self::HIDDEN_BOX));
        self::assertNull($this->repository->contentsOf($scope, self::OTHER_CORP_BOX));
        self::assertSame(
            [self::HIDDEN_BOX],
            array_column($this->repository->rootItemsOf(AssetScope::forCorporation(self::CORPORATION_ID, [3]), self::JITA_ID), 'itemId'),
        );
        self::assertSame([], $this->repository->summarizeLocations(AssetScope::forCorporation(self::CORPORATION_ID, [])));
    }

    public function testSearchFindsItemsInContainersWithTheirPathAndLocationsByNameOrSystem(): void
    {
        $scope = $this->personalScopeWithInventory();

        $result = $this->repository->search($scope, 'TRIT', 50);

        self::assertFalse($result['truncated']);
        $pathByItem = array_column($result['hits'], 'containerPath', 'itemId');
        ksort($pathByItem);
        self::assertSame(
            [
                self::CARGO_OF_SHIP => [self::SHIP],
                self::TRITANIUM_IN_BOX => [self::BOX],
                self::LOOSE_TRITANIUM => [],
                self::AMARR_TRITANIUM => [],
            ],
            $pathByItem,
        );
        $rootByItem = array_column($result['hits'], 'rootLocationId', 'itemId');
        self::assertSame(self::JITA_ID, $rootByItem[self::TRITANIUM_IN_BOX]);
        self::assertSame(self::AMARR_ID, $rootByItem[self::AMARR_TRITANIUM]);
        self::assertSame([], $result['locationIds']);

        self::assertSame([self::JITA_ID], $this->repository->search($scope, 'jita', 50)['locationIds']);
        self::assertSame([self::AMARR_ID], $this->repository->search($scope, 'Amarr', 50)['locationIds']);
    }

    public function testSearchMatchesCustomItemNamesEscapesWildcardsAndCapsTheHits(): void
    {
        $scope = $this->personalScopeWithInventory();

        self::assertSame([self::BOX], array_column($this->repository->search($scope, 'ore stash', 50)['hits'], 'itemId'));
        self::assertSame([], $this->repository->search($scope, '%%', 50)['hits']);
        self::assertSame([], $this->repository->search($scope, 'tr_t', 50)['hits']);

        $capped = $this->repository->search($scope, 'trit', 2);

        self::assertTrue($capped['truncated']);
        self::assertCount(2, $capped['hits']);
    }

    public function testCorporationSearchNeverReturnsContentOfAHiddenDivision(): void
    {
        $scope = $this->corporationScopeWithBoxes([1, 2]);

        self::assertSame([], $this->repository->search($scope, 'veldspar', 50)['hits']);
        self::assertSame(
            [self::HIDDEN_VELDSPAR_IN_BOX],
            array_column($this->repository->search(AssetScope::forCorporation(self::CORPORATION_ID, [3]), 'veldspar', 50)['hits'], 'itemId'),
        );
    }

    public function testSearchIgnoresAccentsAndCaseInTheTermAndInTheStoredNames(): void
    {
        $pilot = $this->createCharacter(null, 'Ilse Varrow');
        $this->em->persist($this->personal($pilot, self::BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita')->setIsSingleton(true)->setItemName('Réserve de Matériaux'));
        $this->em->persist($this->personal($pilot, self::LOOSE_TRITANIUM, self::TRITANIUM_TYPE, 'Tritanium', 10, self::AMARR_ID, 'Jíta Ñandú', 'station', 'Hangar', 'Amarr'));
        $this->flushAndClear();
        $scope = AssetScope::forCharacter($pilot->getId()->toRfc4122());

        self::assertSame([self::BOX], array_column($this->repository->search($scope, 'materiaux', 50)['hits'], 'itemId'));
        self::assertSame([self::BOX], array_column($this->repository->search($scope, 'MATÉRIAUX', 50)['hits'], 'itemId'));
        self::assertSame([self::BOX], array_column($this->repository->search($scope, 'réserve de matér', 50)['hits'], 'itemId'));
        self::assertSame([self::AMARR_ID], $this->repository->search($scope, 'jita nandu', 50)['locationIds']);
        self::assertSame([], $this->repository->search($scope, 'matiriaux', 50)['hits']);
    }

    #[Group('issue-1395')]
    public function testQuantitiesAtARootLocationCountDirectItemsContainersAndSubContainersOfThePersonalScope(): void
    {
        $scope = $this->stockInventory();

        $quantities = $this->repository->quantitiesByRootLocation($scope, [self::JITA_ID], [self::TRITANIUM_TYPE, self::PYERITE_TYPE]);

        self::assertSame([self::JITA_ID => [self::TRITANIUM_TYPE => 433, self::PYERITE_TYPE => 120]], $this->sortedByLocationAndType($quantities));
    }

    #[Group('issue-1395')]
    public function testQuantitiesAreGroupedPerRootLocationAndContainersOfAnotherLocationStayThere(): void
    {
        $scope = $this->stockInventory();

        $quantities = $this->repository->quantitiesByRootLocation($scope, [self::JITA_ID, self::AMARR_ID, self::STOCK_STRUCTURE_ID], [self::TRITANIUM_TYPE, self::PYERITE_TYPE]);

        self::assertSame(
            [
                self::JITA_ID => [self::TRITANIUM_TYPE => 433, self::PYERITE_TYPE => 120],
                self::AMARR_ID => [self::TRITANIUM_TYPE => 6_000, self::PYERITE_TYPE => 77],
                self::STOCK_STRUCTURE_ID => [self::TRITANIUM_TYPE => 250],
            ],
            $this->sortedByLocationAndType($quantities),
        );
    }

    #[Group('issue-1395')]
    public function testQuantitiesIgnoreTypesOutOfTheListAndLocationsOutOfTheList(): void
    {
        $scope = $this->stockInventory();

        self::assertSame(
            [self::AMARR_ID => [self::PYERITE_TYPE => 77]],
            $this->repository->quantitiesByRootLocation($scope, [self::AMARR_ID], [self::PYERITE_TYPE]),
        );
        self::assertSame(
            [self::JITA_ID => [self::VELDSPAR_TYPE => 15]],
            $this->repository->quantitiesByRootLocation($scope, [self::JITA_ID], [self::VELDSPAR_TYPE]),
        );
        self::assertSame([], $this->repository->quantitiesByRootLocation($scope, [self::STOCK_STRUCTURE_ID], [self::PYERITE_TYPE]));
        self::assertSame([], $this->repository->quantitiesByRootLocation($scope, [999_999_999], [self::TRITANIUM_TYPE]));
    }

    #[Group('issue-1395')]
    public function testQuantitiesOfAnotherCharacterAndCorporationAssetsAreNotInThePersonalScope(): void
    {
        $pilotScope = $this->stockInventory();
        $otherScope = AssetScope::forCharacter($this->stockOtherCharacterId);

        self::assertSame(
            [self::JITA_ID => [self::TRITANIUM_TYPE => 9_999]],
            $this->repository->quantitiesByRootLocation($otherScope, [self::JITA_ID], [self::TRITANIUM_TYPE]),
        );
        self::assertSame(
            [self::JITA_ID => [self::TRITANIUM_TYPE => 433]],
            $this->repository->quantitiesByRootLocation($pilotScope, [self::JITA_ID], [self::TRITANIUM_TYPE]),
        );
    }

    #[Group('issue-1395')]
    public function testEmptyLocationOrTypeListsGiveNothing(): void
    {
        $scope = $this->stockInventory();

        self::assertSame([], $this->repository->quantitiesByRootLocation($scope, [], [self::TRITANIUM_TYPE]));
        self::assertSame([], $this->repository->quantitiesByRootLocation($scope, [self::JITA_ID], []));
        self::assertSame([], $this->repository->quantitiesByRootLocation($scope, [], []));
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantitiesCountItemsAndContainersUnderAnUnresolvedStructureName(): void
    {
        $scope = $this->customsOfficeInventory();

        self::assertSame(
            [self::ROBOTICS_TYPE => 1_500],
            $this->repository->customsOfficeQuantities($scope, [self::ROBOTICS_TYPE]),
        );
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantitiesCountItemsUnderAnUnresolvedLocationName(): void
    {
        $scope = $this->customsOfficeInventory();

        self::assertSame(
            [self::COOLANT_TYPE => 90],
            $this->repository->customsOfficeQuantities($scope, [self::COOLANT_TYPE]),
        );
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantitiesAreGroupedPerTypeAndLeaveOutTypesOutOfTheList(): void
    {
        $scope = $this->customsOfficeInventory();

        $quantities = $this->repository->customsOfficeQuantities($scope, [self::COOLANT_TYPE, self::ROBOTICS_TYPE, self::BACTERIA_TYPE, self::TRITANIUM_TYPE]);
        ksort($quantities);

        self::assertSame([self::COOLANT_TYPE => 90, self::ROBOTICS_TYPE => 1_500], $quantities);
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantitiesIgnoreResolvedStationsNamedStructuresAndSolarSystems(): void
    {
        $scope = $this->customsOfficeInventory();

        self::assertSame([], $this->repository->customsOfficeQuantities($scope, [self::BACTERIA_TYPE]));
        self::assertSame(
            [self::ROBOTICS_TYPE => 1_500],
            $this->repository->customsOfficeQuantities($scope, [self::ROBOTICS_TYPE]),
        );
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantitiesOfAnotherCharacterAndCorporationAssetsAreNotInThePersonalScope(): void
    {
        $pilotScope = $this->customsOfficeInventory();
        $otherScope = AssetScope::forCharacter($this->stockOtherCharacterId);

        self::assertSame(
            [self::ROBOTICS_TYPE => 700],
            $this->repository->customsOfficeQuantities($otherScope, [self::ROBOTICS_TYPE]),
        );
        self::assertSame(
            [self::ROBOTICS_TYPE => 1_500],
            $this->repository->customsOfficeQuantities($pilotScope, [self::ROBOTICS_TYPE]),
        );
    }

    #[Group('issue-1390')]
    public function testCustomsOfficeQuantitiesOfAnEmptyTypeListAreEmpty(): void
    {
        $scope = $this->customsOfficeInventory();

        self::assertSame([], $this->repository->customsOfficeQuantities($scope, []));
    }

    #[Group('issue-1451')]
    public function testElsewhereQuantitiesCountEveryRootOutsideTheExcludedLocationsAndLeaveCustomsOfficesOut(): void
    {
        $scope = $this->customsOfficeInventory();

        self::assertSame(
            [self::ROBOTICS_TYPE => 843],
            $this->repository->elsewhereQuantities($scope, [self::JITA_ID], [self::ROBOTICS_TYPE]),
        );
    }

    #[Group('issue-1451')]
    public function testElsewhereQuantitiesFollowContainersAndLeaveOutTheContentOfAnExcludedStation(): void
    {
        $scope = $this->customsOfficeInventory();

        self::assertSame(
            [self::BACTERIA_TYPE => 80],
            $this->repository->elsewhereQuantities($scope, [self::JITA_ID], [self::BACTERIA_TYPE]),
        );
        self::assertSame(
            [self::BACTERIA_TYPE => 45],
            $this->repository->elsewhereQuantities($scope, [self::JITA_ID, self::NAMED_OTHER_STRUCTURE_ID], [self::BACTERIA_TYPE]),
        );
    }

    #[Group('issue-1451')]
    public function testElsewhereQuantitiesWithoutAnyExcludedLocationCountEveryNonCustomsRoot(): void
    {
        $scope = $this->customsOfficeInventory();

        self::assertSame(
            [self::ROBOTICS_TYPE => 1_243],
            $this->repository->elsewhereQuantities($scope, [], [self::ROBOTICS_TYPE]),
        );
    }

    #[Group('issue-1451')]
    public function testElsewhereQuantitiesIgnoreTypesOutOfTheListAndTypesHeldOnlyInACustomsOffice(): void
    {
        $scope = $this->customsOfficeInventory();

        self::assertSame([], $this->repository->elsewhereQuantities($scope, [self::JITA_ID], [self::COOLANT_TYPE, self::TRITANIUM_TYPE]));
        self::assertSame([], $this->repository->elsewhereQuantities($scope, [self::JITA_ID], []));
    }

    #[Group('issue-1451')]
    public function testElsewhereQuantitiesOfAnotherCharacterAreNotInThePersonalScope(): void
    {
        $this->customsOfficeInventory();
        $otherScope = AssetScope::forCharacter($this->stockOtherCharacterId);

        self::assertSame([], $this->repository->elsewhereQuantities($otherScope, [self::JITA_ID], [self::ROBOTICS_TYPE]));
    }

    private function customsOfficeInventory(): AssetScope
    {
        $pilot = $this->createCharacter(null, 'Ilse Varrow');
        $other = $this->createCharacter(null, 'Someone Else');
        $structureName = 'Structure #'.self::UNRESOLVED_STRUCTURE_ID;
        $locationName = 'Location #'.self::UNRESOLVED_LOCATION_ID;

        $this->em->persist($this->personal($pilot, self::CUSTOMS_ROBOTICS_LOOSE, self::ROBOTICS_TYPE, 'Robotics', 1_200, self::UNRESOLVED_STRUCTURE_ID, $structureName, 'other', 'Hangar', null));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::UNRESOLVED_STRUCTURE_ID, $structureName, 'other', 'Hangar', null)->setIsSingleton(true));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_ROBOTICS_IN_BOX, self::ROBOTICS_TYPE, 'Robotics', 300, self::CUSTOMS_BOX, $structureName, 'item', 'Unlocked', null));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_COOLANT_AT_LOCATION, self::COOLANT_TYPE, 'Coolant', 90, self::UNRESOLVED_LOCATION_ID, $locationName, 'other', 'Hangar', null));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_ROBOTICS_IN_STATION, self::ROBOTICS_TYPE, 'Robotics', 400, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita'));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_STATION_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita')->setIsSingleton(true));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_BACTERIA_IN_STATION, self::BACTERIA_TYPE, 'Bacteria', 25, self::CUSTOMS_STATION_BOX, 'Jita IV', 'item', 'Unlocked', 'Jita'));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_BACTERIA_IN_NAMED_STRUCTURE, self::BACTERIA_TYPE, 'Bacteria', 35, self::NAMED_OTHER_STRUCTURE_ID, 'Ashab - Market Fortizar', 'other', 'Hangar', 'Ashab'));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_ROBOTICS_IN_NAMED_STRUCTURE, self::ROBOTICS_TYPE, 'Robotics', 777, self::NAMED_OTHER_STRUCTURE_ID, 'Ashab - Market Fortizar', 'other', 'Hangar', 'Ashab'));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_BACTERIA_IN_SOLAR_SYSTEM, self::BACTERIA_TYPE, 'Bacteria', 45, self::SOLAR_SYSTEM_ID, 'Location #'.self::SOLAR_SYSTEM_ID, 'solar_system', 'Hangar', 'Jita'));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_ROBOTICS_IN_SOLAR_SYSTEM, self::ROBOTICS_TYPE, 'Robotics', 66, self::SOLAR_SYSTEM_ID, 'Structure #'.self::SOLAR_SYSTEM_ID, 'solar_system', 'Hangar', 'Jita'));
        $this->em->persist($this->personal($other, self::CUSTOMS_OTHER_CHARACTER_ROBOTICS, self::ROBOTICS_TYPE, 'Robotics', 700, self::UNRESOLVED_STRUCTURE_ID, $structureName, 'other', 'Hangar', null));
        $this->em->persist($this->personal($pilot, self::CUSTOMS_CORPORATION_ROBOTICS, self::ROBOTICS_TYPE, 'Robotics', 5_555, self::UNRESOLVED_STRUCTURE_ID, $structureName, 'other', 'CorpSAG1', null)->setCorporationId(self::CORPORATION_ID)->setIsCorporationAsset(true));
        $this->flushAndClear();
        $this->stockOtherCharacterId = $other->getId()->toRfc4122();

        return AssetScope::forCharacter($pilot->getId()->toRfc4122());
    }

    private function personalScopeWithInventory(): AssetScope
    {
        $pilot = $this->createCharacter(null, 'Ilse Varrow');
        $other = $this->createCharacter(null, 'Someone Else');

        $this->em->persist($this->personal($pilot, self::SHIP, self::SHIP_TYPE, 'Merlin', 1, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita')->setIsSingleton(true));
        $this->em->persist($this->personal($pilot, self::CARGO_OF_SHIP, self::TRITANIUM_TYPE, 'Tritanium', 300, self::SHIP, 'Jita IV', 'item', 'Cargo', 'Jita'));
        $this->em->persist($this->personal($pilot, self::BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita')->setIsSingleton(true)->setItemName('Ore stash'));
        $this->em->persist($this->personal($pilot, self::TRITANIUM_IN_BOX, self::TRITANIUM_TYPE, 'Tritanium', 700, self::BOX, 'Jita IV', 'item', 'Unlocked', 'Jita'));
        $this->em->persist($this->personal($pilot, self::INNER_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::BOX, 'Jita IV', 'item', 'Unlocked', 'Jita')->setIsSingleton(true));
        $this->em->persist($this->personal($pilot, self::PYERITE_IN_INNER_BOX, self::PYERITE_TYPE, 'Pyerite', 50, self::INNER_BOX, 'Jita IV', 'item', 'Unlocked', 'Jita'));
        $this->em->persist($this->personal($pilot, self::LOOSE_TRITANIUM, self::TRITANIUM_TYPE, 'Tritanium', 1_000, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita'));
        $this->em->persist($this->personal($pilot, self::AMARR_TRITANIUM, self::TRITANIUM_TYPE, 'Tritanium', 5, self::AMARR_ID, 'Amarr VIII', 'station', 'Hangar', 'Amarr'));
        $this->em->persist($this->personal($pilot, self::ORPHAN_SHIP, self::SHIP_TYPE, 'Merlin', 1, 999_000_999, 'Unknown structure', 'item', 'Hangar', null)->setIsSingleton(true));
        $this->em->persist($this->personal($other, 1_878_000_090, self::TRITANIUM_TYPE, 'Tritanium', 9_999, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita'));
        $this->em->persist($this->personal($pilot, 1_878_000_091, self::TRITANIUM_TYPE, 'Tritanium', 8_888, self::JITA_ID, 'Jita IV', 'station', 'CorpSAG1', 'Jita')->setCorporationId(self::CORPORATION_ID)->setIsCorporationAsset(true));
        $this->flushAndClear();

        return AssetScope::forCharacter($pilot->getId()->toRfc4122());
    }

    private function stockInventory(): AssetScope
    {
        $pilot = $this->createCharacter(null, 'Ilse Varrow');
        $other = $this->createCharacter(null, 'Someone Else');

        $this->em->persist($this->personal($pilot, self::STOCK_LOOSE_TRITANIUM, self::TRITANIUM_TYPE, 'Tritanium', 400, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita'));
        $this->em->persist($this->personal($pilot, self::STOCK_JITA_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita')->setIsSingleton(true));
        $this->em->persist($this->personal($pilot, self::STOCK_PYERITE_IN_JITA_BOX, self::PYERITE_TYPE, 'Pyerite', 120, self::STOCK_JITA_BOX, 'Jita IV', 'item', 'Unlocked', 'Jita'));
        $this->em->persist($this->personal($pilot, self::STOCK_JITA_INNER_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::STOCK_JITA_BOX, 'Jita IV', 'item', 'Unlocked', 'Jita')->setIsSingleton(true));
        $this->em->persist($this->personal($pilot, self::STOCK_TRITANIUM_IN_INNER_BOX, self::TRITANIUM_TYPE, 'Tritanium', 33, self::STOCK_JITA_INNER_BOX, 'Jita IV', 'item', 'Unlocked', 'Jita'));
        $this->em->persist($this->personal($pilot, self::STOCK_VELDSPAR, self::VELDSPAR_TYPE, 'Veldspar', 15, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita'));
        $this->em->persist($this->personal($pilot, self::STOCK_AMARR_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::AMARR_ID, 'Amarr VIII', 'station', 'Hangar', 'Amarr')->setIsSingleton(true));
        $this->em->persist($this->personal($pilot, self::STOCK_TRITANIUM_IN_AMARR_BOX, self::TRITANIUM_TYPE, 'Tritanium', 6_000, self::STOCK_AMARR_BOX, 'Amarr VIII', 'item', 'Unlocked', 'Amarr'));
        $this->em->persist($this->personal($pilot, self::STOCK_LOOSE_PYERITE_AT_AMARR, self::PYERITE_TYPE, 'Pyerite', 77, self::AMARR_ID, 'Amarr VIII', 'station', 'Hangar', 'Amarr'));
        $this->em->persist($this->personal($pilot, self::STOCK_STRUCTURE_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::STOCK_STRUCTURE_ID, 'Ashab - Market Fortizar', 'item', 'Hangar', 'Ashab')->setIsSingleton(true));
        $this->em->persist($this->personal($pilot, self::STOCK_TRITANIUM_IN_STRUCTURE_BOX, self::TRITANIUM_TYPE, 'Tritanium', 250, self::STOCK_STRUCTURE_BOX, 'Ashab - Market Fortizar', 'item', 'Unlocked', 'Ashab'));
        $this->em->persist($this->personal($other, self::STOCK_OTHER_CHARACTER_TRITANIUM, self::TRITANIUM_TYPE, 'Tritanium', 9_999, self::JITA_ID, 'Jita IV', 'station', 'Hangar', 'Jita'));
        $this->em->persist($this->personal($pilot, self::STOCK_CORPORATION_TRITANIUM, self::TRITANIUM_TYPE, 'Tritanium', 8_888, self::JITA_ID, 'Jita IV', 'station', 'CorpSAG1', 'Jita')->setCorporationId(self::CORPORATION_ID)->setIsCorporationAsset(true));
        $this->flushAndClear();
        $this->stockOtherCharacterId = $other->getId()->toRfc4122();

        return AssetScope::forCharacter($pilot->getId()->toRfc4122());
    }

    /**
     * @param array<int, array<int, int>> $quantities
     *
     * @return array<int, array<int, int>>
     */
    private function sortedByLocationAndType(array $quantities): array
    {
        foreach ($quantities as &$byType) {
            ksort($byType);
        }
        unset($byType);
        ksort($quantities);

        return $quantities;
    }

    /**
     * @param list<int> $visibleDivisions
     */
    private function corporationScopeWithBoxes(array $visibleDivisions): AssetScope
    {
        $this->em->persist($this->corporate(self::CORP_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'station', 'CorpSAG2', 'Reactions', 'Jita IV', 'Jita'));
        $this->em->persist($this->corporate(self::CORP_TRITANIUM_IN_BOX, self::TRITANIUM_TYPE, 'Tritanium', 40, self::CORP_BOX, 'item', 'Unlocked', 'Reactions', 'Jita IV', 'Jita'));
        $this->em->persist($this->corporate(self::CORP_LOOSE_TRITANIUM, self::TRITANIUM_TYPE, 'Tritanium', 10, self::JITA_ID, 'station', 'CorpSAG2', 'Reactions', 'Jita IV', 'Jita'));
        $this->em->persist($this->corporate(self::HIDDEN_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'station', 'CorpSAG3', 'Vault', 'Jita IV', 'Jita'));
        $this->em->persist($this->corporate(self::HIDDEN_VELDSPAR_IN_BOX, 1_230, 'Veldspar', 900, self::HIDDEN_BOX, 'item', 'Unlocked', 'Vault', 'Jita IV', 'Jita'));
        $this->em->persist($this->corporate(self::AMARR_CORP_TRITANIUM, self::TRITANIUM_TYPE, 'Tritanium', 7, self::AMARR_ID, 'station', 'CorpSAG1', 'Minerals', 'Amarr VIII', 'Amarr'));
        $this->em->persist($this->corporate(self::OTHER_CORP_BOX, self::CONTAINER_TYPE, 'Giant Secure Container', 1, self::JITA_ID, 'station', 'CorpSAG2', 'Reactions', 'Jita IV', 'Jita')->setCorporationId(self::OTHER_CORPORATION_ID));
        $this->flushAndClear();

        return AssetScope::forCorporation(self::CORPORATION_ID, $visibleDivisions);
    }

    private function personal(Character $character, int $itemId, int $typeId, string $typeName, int $quantity, int $locationId, string $locationName, string $locationType, string $flag, ?string $system): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName($locationName)
            ->setLocationType($locationType)
            ->setLocationFlag($flag)
            ->setSolarSystemName($system)
            ->setCharacter($character)
            ->setIsCorporationAsset(false);
    }

    private function corporate(int $itemId, int $typeId, string $typeName, int $quantity, int $locationId, string $locationType, string $flag, string $division, string $locationName, string $system): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName($locationName)
            ->setLocationType($locationType)
            ->setLocationFlag($flag)
            ->setDivisionName($division)
            ->setSolarSystemName($system)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);
    }

    private function persistSde(): void
    {
        $categories = [];
        foreach ([2 => 'Celestial', 4 => 'Material', 6 => 'Ship'] as $categoryId => $name) {
            $categories[$categoryId] = (new InvCategory())->setCategoryId($categoryId)->setCategoryName($name)->setPublished(true);
            $this->em->persist($categories[$categoryId]);
        }
        $groups = [];
        foreach ([[12, 'Cargo Container', 2], [18, 'Mineral', 4], [25, 'Frigate', 6]] as [$groupId, $name, $categoryId]) {
            $groups[$groupId] = (new InvGroup())->setGroupId($groupId)->setGroupName($name)->setCategory($categories[$categoryId])->setPublished(true);
            $this->em->persist($groups[$groupId]);
        }
        foreach ([
            [self::SHIP_TYPE, 'Merlin', 25],
            [self::TRITANIUM_TYPE, 'Tritanium', 18],
            [self::PYERITE_TYPE, 'Pyerite', 18],
            [1_230, 'Veldspar', 18],
            [self::ROBOTICS_TYPE, 'Robotics', 18],
            [self::COOLANT_TYPE, 'Coolant', 18],
            [self::BACTERIA_TYPE, 'Bacteria', 18],
            [self::CONTAINER_TYPE, 'Giant Secure Container', 12],
        ] as [$typeId, $name, $groupId]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($groups[$groupId])->setPublished(true));
        }
        $this->em->flush();
    }
}
