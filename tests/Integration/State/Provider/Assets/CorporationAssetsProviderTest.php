<?php

declare(strict_types=1);

namespace App\Tests\Integration\State\Provider\Assets;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Assets\AssetItemResource;
use App\ApiResource\Assets\CorporationAssetsResource;
use App\Entity\CachedAsset;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Provider\Assets\CorporationAssetsProvider;
use App\Tests\Integration\IntegrationTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /me/corporation/assets for a corporation member: divisions are only visible once a
 * director allows them (CorpAssetVisibility whitelist), and only CorpSAG hangars are shown.
 *
 * Integration rather than unit: the real repositories and database decide which rows come
 * back, so the expectations do not depend on which finder the provider calls.
 * The SDE is empty in the test database: categoryId resolves to null.
 */
final class CorporationAssetsProviderTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_000_001; // corporation of IntegrationTestCase::createCharacter()
    private const int JITA_STATION_ID = 60_003_760;
    private const int OFFICE_ITEM_ID = 1_040_000_000_001;

    private const int MATERIALS_TRITANIUM_ITEM_ID = 1_040_000_000_101;
    private const int SHIPS_RIFTER_ITEM_ID = 1_040_000_000_301;
    private const int SHIPS_FUEL_CONTAINER_ITEM_ID = 1_040_000_000_302;
    private const int FUEL_CONTAINER_NITROGEN_ISOTOPES_ITEM_ID = 1_040_000_000_303;
    private const int DIRECTOR_STASH_PLEX_ITEM_ID = 1_040_000_000_701;
    private const int DIRECTOR_STASH_CONTAINER_ITEM_ID = 1_040_000_000_702;
    private const int DIRECTOR_CONTAINER_HELIUM_ISOTOPES_ITEM_ID = 1_040_000_000_703;
    private const int CORP_DELIVERIES_MEXALLON_ITEM_ID = 1_040_000_000_901;
    private const int IMPOUNDED_ISOGEN_ITEM_ID = 1_040_000_000_902;

    private User $member;
    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->member = $this->createCorporationMember('Member Pilot');
        $this->director = $this->createCorporationMember('Director Pilot');

        $this->createCorporationAsset(self::OFFICE_ITEM_ID, 27, 'Office', 1, self::JITA_STATION_ID, 'station', 'OfficeFolder', null);
        $this->createCorporationAsset(self::MATERIALS_TRITANIUM_ITEM_ID, 34, 'Tritanium', 1_500_000, self::OFFICE_ITEM_ID, 'item', 'CorpSAG1', 'Materials');
        $this->createCorporationAsset(self::SHIPS_RIFTER_ITEM_ID, 587, 'Rifter', 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG3', 'Ships');
        $this->createCorporationAsset(self::SHIPS_FUEL_CONTAINER_ITEM_ID, 17366, 'Station Container', 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG3', 'Ships', 'Fuel');
        // Item inside the "Fuel" container: ESI points location_id at the container and does not
        // repeat the hangar flag, so the sync stores no CorpSAG flag and no division name.
        $this->createCorporationAsset(self::FUEL_CONTAINER_NITROGEN_ISOTOPES_ITEM_ID, 17888, 'Nitrogen Isotopes', 40_000, self::SHIPS_FUEL_CONTAINER_ITEM_ID, 'item', 'Unlocked', null);
        $this->createCorporationAsset(self::DIRECTOR_STASH_PLEX_ITEM_ID, 44992, 'PLEX', 500, self::OFFICE_ITEM_ID, 'item', 'CorpSAG7', 'Director Stash');
        $this->createCorporationAsset(self::DIRECTOR_STASH_CONTAINER_ITEM_ID, 17366, 'Station Container', 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG7', 'Director Stash', 'Reserve');
        $this->createCorporationAsset(self::DIRECTOR_CONTAINER_HELIUM_ISOTOPES_ITEM_ID, 16274, 'Helium Isotopes', 60_000, self::DIRECTOR_STASH_CONTAINER_ITEM_ID, 'item', 'Unlocked', null);
        $this->createCorporationAsset(self::CORP_DELIVERIES_MEXALLON_ITEM_ID, 36, 'Mexallon', 20_000, self::JITA_STATION_ID, 'station', 'CorpDeliveries', null);
        $this->createCorporationAsset(self::IMPOUNDED_ISOGEN_ITEM_ID, 37, 'Isogen', 8_000, self::JITA_STATION_ID, 'station', 'Impounded', null);
        $this->flushAndClear();
    }

    public function testWithoutVisibilityConfigMemberSeesNoCorporationAssets(): void
    {
        $result = $this->listCorporationAssetsAs($this->member);

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    public function testWithoutVisibilityConfigDivisionNameFilterStillShowsNothing(): void
    {
        $result = $this->listCorporationAssetsAs($this->member, divisionName: 'Materials');

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    public function testMemberSeesOnlyHangarRowsOfAllowedDivisions(): void
    {
        $this->allowDivisions([1, 3]);

        $result = $this->listCorporationAssetsAs($this->member);

        // Hangar rows of divisions 1 and 3 only: no OfficeFolder, CorpDeliveries, Impounded, nor division 7
        self::assertContains(self::MATERIALS_TRITANIUM_ITEM_ID, $this->itemIdsOf($result));
        self::assertContains(self::SHIPS_RIFTER_ITEM_ID, $this->itemIdsOf($result));
        self::assertContains(self::SHIPS_FUEL_CONTAINER_ITEM_ID, $this->itemIdsOf($result));
        self::assertNotContains(self::OFFICE_ITEM_ID, $this->itemIdsOf($result));
        self::assertNotContains(self::CORP_DELIVERIES_MEXALLON_ITEM_ID, $this->itemIdsOf($result));
        self::assertNotContains(self::IMPOUNDED_ISOGEN_ITEM_ID, $this->itemIdsOf($result));
        self::assertNotContains(self::DIRECTOR_STASH_PLEX_ITEM_ID, $this->itemIdsOf($result));
        self::assertNotContains(self::DIRECTOR_STASH_CONTAINER_ITEM_ID, $this->itemIdsOf($result));
        self::assertNotContains(self::DIRECTOR_CONTAINER_HELIUM_ISOTOPES_ITEM_ID, $this->itemIdsOf($result));
    }

    public function testNonHangarFlagsStayHiddenEvenWithEveryDivisionAllowed(): void
    {
        $this->allowDivisions([1, 2, 3, 4, 5, 6, 7]);

        $result = $this->listCorporationAssetsAs($this->member);

        self::assertNotContains(self::OFFICE_ITEM_ID, $this->itemIdsOf($result));
        self::assertNotContains(self::CORP_DELIVERIES_MEXALLON_ITEM_ID, $this->itemIdsOf($result));
        self::assertNotContains(self::IMPOUNDED_ISOGEN_ITEM_ID, $this->itemIdsOf($result));
    }

    /**
     * Issue #54: container contents carry the container's flag ("Unlocked"), not CorpSAG{n}.
     * Expected: they follow the division of the container they sit in.
     */
    public function testItemsInsideAContainerOfAnAllowedDivisionAreVisible(): void
    {
        $this->allowDivisions([1, 3]);

        $result = $this->listCorporationAssetsAs($this->member);

        self::assertEqualsCanonicalizing(
            [
                self::MATERIALS_TRITANIUM_ITEM_ID,
                self::SHIPS_RIFTER_ITEM_ID,
                self::SHIPS_FUEL_CONTAINER_ITEM_ID,
                self::FUEL_CONTAINER_NITROGEN_ISOTOPES_ITEM_ID,
            ],
            $this->itemIdsOf($result),
        );
        self::assertSame(1_540_002, $this->totalQuantityOf($result));
    }

    public function testDivisionNameFilterKeepsOnlyThatAllowedDivision(): void
    {
        $this->allowDivisions([1, 3]);

        $result = $this->listCorporationAssetsAs($this->member, divisionName: 'Materials');

        self::assertSame([self::MATERIALS_TRITANIUM_ITEM_ID], $this->itemIdsOf($result));
        self::assertSame(1_500_000, $this->totalQuantityOf($result));
    }

    public function testDivisionNameFilterOnANotAllowedDivisionShowsNothing(): void
    {
        $this->allowDivisions([1, 3]);

        $result = $this->listCorporationAssetsAs($this->member, divisionName: 'Director Stash');

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    /** @param int[] $divisionNumbers */
    private function allowDivisions(array $divisionNumbers): void
    {
        $visibility = (new CorpAssetVisibility())
            ->setCorporationId(self::CORPORATION_ID)
            ->setVisibleDivisions($divisionNumbers)
            ->setConfiguredBy($this->em->getReference(User::class, $this->director->getId()));
        $this->em->persist($visibility);
        $this->flushAndClear();
    }

    private function listCorporationAssetsAs(User $user, ?string $divisionName = null): CorporationAssetsResource
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->em->find(User::class, $user->getId()));

        $requestStack = new RequestStack();
        $requestStack->push(new Request($divisionName !== null ? ['divisionName' => $divisionName] : []));

        $provider = new CorporationAssetsProvider(
            $security,
            self::getContainer()->get(CachedAssetRepository::class),
            self::getContainer()->get(CorpAssetVisibilityRepository::class),
            self::getContainer()->get(InvTypeRepository::class),
            $requestStack,
        );

        return $provider->provide(new Get());
    }

    private function createCorporationMember(string $name): User
    {
        $character = $this->createCharacter(name: $name); // corporation 98_000_001
        $user = $character->getUser();
        \assert($user instanceof User);
        $user->setMainCharacter($character);

        return $user;
    }

    private function createCorporationAsset(
        int $itemId,
        int $typeId,
        string $typeName,
        int $quantity,
        int $locationId,
        string $locationType,
        string $locationFlag,
        ?string $divisionName,
        ?string $itemName = null,
    ): void {
        $this->em->persist((new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType($locationType)
            ->setLocationFlag($locationFlag)
            ->setDivisionName($divisionName)
            ->setItemName($itemName)
            ->setSolarSystemId(30_000_142)
            ->setSolarSystemName('Jita')
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true));
    }

    /** @return list<int> */
    private function itemIdsOf(CorporationAssetsResource $result): array
    {
        return array_values(array_map(static fn (AssetItemResource $item): int => $item->itemId, $result->items));
    }

    private function totalQuantityOf(CorporationAssetsResource $result): int
    {
        return array_sum(array_map(static fn (AssetItemResource $item): int => $item->quantity, $result->items));
    }
}
