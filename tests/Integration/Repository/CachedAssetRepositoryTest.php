<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\CachedAsset;
use App\Repository\CachedAssetRepository;
use App\Tests\Integration\IntegrationTestCase;

/**
 * Corporation asset finders against PostgreSQL, with the rows an ESI
 * /corporations/{id}/assets/ sync of a Jita office leaves in cached_assets.
 */
final class CachedAssetRepositoryTest extends IntegrationTestCase
{
    private const int CORPORATION_ID = 98_000_001;
    private const int OTHER_CORPORATION_ID = 98_000_002;
    private const int JITA_STATION_ID = 60_003_760;
    private const int OFFICE_ITEM_ID = 1_040_000_000_001;

    private const int MATERIALS_TRITANIUM_ITEM_ID = 1_040_000_000_101;
    private const int MATERIALS_PYERITE_ITEM_ID = 1_040_000_000_102;
    private const int SHIPS_RIFTER_ITEM_ID = 1_040_000_000_301;
    private const int DIRECTOR_STASH_PLEX_ITEM_ID = 1_040_000_000_701;
    private const int CORP_DELIVERIES_MEXALLON_ITEM_ID = 1_040_000_000_901;
    private const int IMPOUNDED_ISOGEN_ITEM_ID = 1_040_000_000_902;
    private const int OTHER_CORPORATION_TRITANIUM_ITEM_ID = 1_040_000_009_101;

    private CachedAssetRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get(CachedAssetRepository::class);

        $this->createCorporationAsset(self::OFFICE_ITEM_ID, 27, 'Office', 1, self::JITA_STATION_ID, 'station', 'OfficeFolder', null);
        $this->createCorporationAsset(self::MATERIALS_TRITANIUM_ITEM_ID, 34, 'Tritanium', 1_500_000, self::OFFICE_ITEM_ID, 'item', 'CorpSAG1', 'Materials');
        $this->createCorporationAsset(self::MATERIALS_PYERITE_ITEM_ID, 35, 'Pyerite', 750_000, self::OFFICE_ITEM_ID, 'item', 'CorpSAG1', 'Materials');
        $this->createCorporationAsset(self::SHIPS_RIFTER_ITEM_ID, 587, 'Rifter', 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG3', 'Ships');
        $this->createCorporationAsset(self::DIRECTOR_STASH_PLEX_ITEM_ID, 44992, 'PLEX', 500, self::OFFICE_ITEM_ID, 'item', 'CorpSAG7', 'Director Stash');
        $this->createCorporationAsset(self::CORP_DELIVERIES_MEXALLON_ITEM_ID, 36, 'Mexallon', 20_000, self::JITA_STATION_ID, 'station', 'CorpDeliveries', null);
        $this->createCorporationAsset(self::IMPOUNDED_ISOGEN_ITEM_ID, 37, 'Isogen', 8_000, self::JITA_STATION_ID, 'station', 'Impounded', null);
        $this->createCorporationAsset(self::OTHER_CORPORATION_TRITANIUM_ITEM_ID, 34, 'Tritanium', 999, self::JITA_STATION_ID, 'item', 'CorpSAG1', 'Materials', self::OTHER_CORPORATION_ID);
        $this->flushAndClear();
    }

    public function testFindByCorporationAndDivisionsReturnsOnlyAllowedHangars(): void
    {
        $assets = $this->repository->findByCorporationAndDivisions(self::CORPORATION_ID, [1, 3]);

        self::assertEqualsCanonicalizing(
            [self::MATERIALS_TRITANIUM_ITEM_ID, self::MATERIALS_PYERITE_ITEM_ID, self::SHIPS_RIFTER_ITEM_ID],
            $this->itemIdsOf($assets),
        );
        self::assertSame(2_250_001, array_sum(array_map(static fn (CachedAsset $a): int => $a->getQuantity(), $assets)));
    }

    public function testFindByCorporationAndDivisionsNeverReturnsNonHangarFlags(): void
    {
        $assets = $this->repository->findByCorporationAndDivisions(self::CORPORATION_ID, [1, 2, 3, 4, 5, 6, 7]);

        self::assertEqualsCanonicalizing(
            [
                self::MATERIALS_TRITANIUM_ITEM_ID,
                self::MATERIALS_PYERITE_ITEM_ID,
                self::SHIPS_RIFTER_ITEM_ID,
                self::DIRECTOR_STASH_PLEX_ITEM_ID,
            ],
            $this->itemIdsOf($assets),
        );
    }

    public function testFindByCorporationAndDivisionsWithNoAllowedDivisionReturnsNothing(): void
    {
        self::assertSame([], $this->repository->findByCorporationAndDivisions(self::CORPORATION_ID, []));
    }

    public function testFindByCorporationDivisionNameAndFlagsKeepsTheNamedAllowedDivision(): void
    {
        $assets = $this->repository->findByCorporationDivisionNameAndFlags(self::CORPORATION_ID, 'Materials', [1, 3]);

        self::assertEqualsCanonicalizing(
            [self::MATERIALS_TRITANIUM_ITEM_ID, self::MATERIALS_PYERITE_ITEM_ID],
            $this->itemIdsOf($assets),
        );
    }

    public function testFindByCorporationDivisionNameAndFlagsIgnoresANamedDivisionThatIsNotAllowed(): void
    {
        self::assertSame([], $this->repository->findByCorporationDivisionNameAndFlags(self::CORPORATION_ID, 'Director Stash', [1, 3]));
    }

    /**
     * Guard: GroupIndustryContainerService reads every corporation asset (container
     * verification by name). Restricting what members see must not go through this finder.
     */
    public function testFindByCorporationIdStillReturnsEveryAssetOfTheCorporation(): void
    {
        self::assertEqualsCanonicalizing(
            [
                self::OFFICE_ITEM_ID,
                self::MATERIALS_TRITANIUM_ITEM_ID,
                self::MATERIALS_PYERITE_ITEM_ID,
                self::SHIPS_RIFTER_ITEM_ID,
                self::DIRECTOR_STASH_PLEX_ITEM_ID,
                self::CORP_DELIVERIES_MEXALLON_ITEM_ID,
                self::IMPOUNDED_ISOGEN_ITEM_ID,
            ],
            $this->itemIdsOf($this->repository->findByCorporationId(self::CORPORATION_ID)),
        );
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
        int $corporationId = self::CORPORATION_ID,
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
            ->setSolarSystemId(30_000_142)
            ->setSolarSystemName('Jita')
            ->setCorporationId($corporationId)
            ->setIsCorporationAsset(true));
    }

    /**
     * @param CachedAsset[] $assets
     * @return list<int>
     */
    private function itemIdsOf(array $assets): array
    {
        return array_values(array_map(static fn (CachedAsset $asset): int => $asset->getItemId(), $assets));
    }
}
