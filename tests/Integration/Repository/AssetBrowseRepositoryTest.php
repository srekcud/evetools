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
            [self::CONTAINER_TYPE, 'Giant Secure Container', 12],
        ] as [$typeId, $name, $groupId]) {
            $this->em->persist((new InvType())->setTypeId($typeId)->setTypeName($name)->setGroup($groups[$groupId])->setPublished(true));
        }
        $this->em->flush();
    }
}
