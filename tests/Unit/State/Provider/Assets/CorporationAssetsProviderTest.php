<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Assets;

use ApiPlatform\Metadata\Get;
use App\ApiResource\Assets\AssetItemResource;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\Sde\InvTypeRepository;
use App\State\Provider\Assets\CorporationAssetsProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Corporation divisions are only visible to members once a director allows them
 * (CorpAssetVisibility is a whitelist). Without any config, members see nothing.
 *
 * The cached asset repository is replaced by an in-memory corporation hangar: each finder
 * answers what its query returns in the database (see CachedAssetRepositoryTest), so the
 * assertions only depend on what the member finally sees.
 */
#[CoversClass(CorporationAssetsProvider::class)]
class CorporationAssetsProviderTest extends TestCase
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

    private Security&Stub $security;
    private CachedAssetRepository&Stub $cachedAssetRepository;
    private CorpAssetVisibilityRepository&Stub $visibilityRepository;
    private RequestStack $requestStack;
    private CorporationAssetsProvider $provider;

    /** @var list<CachedAsset> */
    private array $corporationHangar;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $this->visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('findByTypeIds')->willReturn([]);
        $this->requestStack = new RequestStack();

        $this->corporationHangar = $this->buildCorporationHangar();
        $this->emulateCachedAssetQueries();

        $this->provider = new CorporationAssetsProvider(
            $this->security,
            $this->cachedAssetRepository,
            $this->visibilityRepository,
            $invTypeRepository,
            $this->requestStack,
        );
    }

    // ===========================================
    // Authentication guards
    // ===========================================

    public function testAnonymousReaderIsRejected(): void
    {
        $this->security->method('getUser')->willReturn(null);
        $this->requestStack->push(new Request());

        $this->expectException(UnauthorizedHttpException::class);

        $this->provider->provide(new Get());
    }

    public function testReaderWithoutMainCharacterIsDenied(): void
    {
        $user = $this->createStub(User::class);
        $user->method('getMainCharacter')->willReturn(null);
        $this->security->method('getUser')->willReturn($user);
        $this->requestStack->push(new Request());

        $this->expectException(AccessDeniedHttpException::class);

        $this->provider->provide(new Get());
    }

    // ===========================================
    // No visibility config: nothing allowed yet
    // ===========================================

    public function testWithoutVisibilityConfigMemberSeesNoCorporationAssets(): void
    {
        $this->loginCorporationMember();
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->requestStack->push(new Request());

        $result = $this->provider->provide(new Get());

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    public function testWithoutVisibilityConfigDivisionNameFilterStillShowsNothing(): void
    {
        $this->loginCorporationMember();
        $this->visibilityRepository->method('findByCorporationId')->willReturn(null);
        $this->requestStack->push(new Request(['divisionName' => 'Materials']));

        $result = $this->provider->provide(new Get());

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    // ===========================================
    // With visibility config: allowed CorpSAG divisions only
    // ===========================================

    public function testWithVisibilityConfigMemberSeesOnlyAllowedDivisions(): void
    {
        $this->loginCorporationMember();
        $this->allowDivisions([1, 3]);
        $this->requestStack->push(new Request());

        $result = $this->provider->provide(new Get());

        self::assertSame(3, $result->total);
        self::assertSame(
            [self::MATERIALS_TRITANIUM_ITEM_ID, self::MATERIALS_PYERITE_ITEM_ID, self::SHIPS_RIFTER_ITEM_ID],
            $this->itemIdsOf($result->items),
        );
        self::assertSame(
            ['CorpSAG1', 'CorpSAG1', 'CorpSAG3'],
            array_map(static fn (AssetItemResource $item): string => $item->locationFlag, $result->items),
        );
        self::assertSame(
            [1_500_000, 750_000, 1],
            array_map(static fn (AssetItemResource $item): int => $item->quantity, $result->items),
        );
    }

    public function testWithVisibilityConfigNonHangarFlagsAreNeverShown(): void
    {
        $this->loginCorporationMember();
        $this->allowDivisions([1, 2, 3, 4, 5, 6, 7]);
        $this->requestStack->push(new Request());

        $result = $this->provider->provide(new Get());

        // OfficeFolder, CorpDeliveries and Impounded rows stay hidden even with every division allowed
        self::assertSame(4, $result->total);
        self::assertSame(
            [
                self::MATERIALS_TRITANIUM_ITEM_ID,
                self::MATERIALS_PYERITE_ITEM_ID,
                self::SHIPS_RIFTER_ITEM_ID,
                self::DIRECTOR_STASH_PLEX_ITEM_ID,
            ],
            $this->itemIdsOf($result->items),
        );
    }

    public function testWithVisibilityConfigDivisionNameFilterKeepsOnlyThatAllowedDivision(): void
    {
        $this->loginCorporationMember();
        $this->allowDivisions([1, 3]);
        $this->requestStack->push(new Request(['divisionName' => 'Materials']));

        $result = $this->provider->provide(new Get());

        self::assertSame(2, $result->total);
        self::assertSame(
            [self::MATERIALS_TRITANIUM_ITEM_ID, self::MATERIALS_PYERITE_ITEM_ID],
            $this->itemIdsOf($result->items),
        );
    }

    public function testWithVisibilityConfigDivisionNameOfNotAllowedDivisionShowsNothing(): void
    {
        $this->loginCorporationMember();
        $this->allowDivisions([1, 3]);
        $this->requestStack->push(new Request(['divisionName' => 'Director Stash']));

        $result = $this->provider->provide(new Get());

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    public function testWithEmptyAllowedDivisionsMemberSeesNoCorporationAssets(): void
    {
        $this->loginCorporationMember();
        $this->allowDivisions([]);
        $this->requestStack->push(new Request());

        $result = $this->provider->provide(new Get());

        self::assertSame(0, $result->total);
        self::assertSame([], $result->items);
    }

    // ===========================================
    // Helpers
    // ===========================================

    private function loginCorporationMember(): void
    {
        $character = $this->createStub(Character::class);
        $character->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::v4());
        $user->method('getMainCharacter')->willReturn($character);

        $this->security->method('getUser')->willReturn($user);
    }

    /** @param int[] $divisionNumbers */
    private function allowDivisions(array $divisionNumbers): void
    {
        $visibility = new CorpAssetVisibility();
        $visibility->setCorporationId(self::CORPORATION_ID);
        $visibility->setVisibleDivisions($divisionNumbers);
        $visibility->setConfiguredBy($this->createStub(User::class));

        $this->visibilityRepository->method('findByCorporationId')->willReturn($visibility);
    }

    /**
     * Same rows as an ESI /corporations/{id}/assets/ sync of a Jita office:
     * divisions 1 "Materials", 3 "Ships", 7 "Director Stash", plus non-hangar flags.
     *
     * @return list<CachedAsset>
     */
    private function buildCorporationHangar(): array
    {
        return [
            $this->corporationAsset(self::OFFICE_ITEM_ID, 27, 'Office', 1, self::JITA_STATION_ID, 'station', 'OfficeFolder', null),
            $this->corporationAsset(self::MATERIALS_TRITANIUM_ITEM_ID, 34, 'Tritanium', 1_500_000, self::OFFICE_ITEM_ID, 'item', 'CorpSAG1', 'Materials'),
            $this->corporationAsset(self::MATERIALS_PYERITE_ITEM_ID, 35, 'Pyerite', 750_000, self::OFFICE_ITEM_ID, 'item', 'CorpSAG1', 'Materials'),
            $this->corporationAsset(self::SHIPS_RIFTER_ITEM_ID, 587, 'Rifter', 1, self::OFFICE_ITEM_ID, 'item', 'CorpSAG3', 'Ships'),
            $this->corporationAsset(self::DIRECTOR_STASH_PLEX_ITEM_ID, 44992, 'PLEX', 500, self::OFFICE_ITEM_ID, 'item', 'CorpSAG7', 'Director Stash'),
            $this->corporationAsset(self::CORP_DELIVERIES_MEXALLON_ITEM_ID, 36, 'Mexallon', 20_000, self::JITA_STATION_ID, 'station', 'CorpDeliveries', null),
            $this->corporationAsset(self::IMPOUNDED_ISOGEN_ITEM_ID, 37, 'Isogen', 8_000, self::JITA_STATION_ID, 'station', 'Impounded', null),
            $this->corporationAsset(self::OTHER_CORPORATION_TRITANIUM_ITEM_ID, 34, 'Tritanium', 999, self::JITA_STATION_ID, 'item', 'CorpSAG1', 'Materials', self::OTHER_CORPORATION_ID),
        ];
    }

    private function corporationAsset(
        int $itemId,
        int $typeId,
        string $typeName,
        int $quantity,
        int $locationId,
        string $locationType,
        string $locationFlag,
        ?string $divisionName,
        int $corporationId = self::CORPORATION_ID,
    ): CachedAsset {
        return (new CachedAsset())
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
            ->setIsCorporationAsset(true);
    }

    /** Each finder returns what its DQL selects (checked against PostgreSQL by CachedAssetRepositoryTest). */
    private function emulateCachedAssetQueries(): void
    {
        $inCorporation = fn (int $corporationId): array => array_values(array_filter(
            $this->corporationHangar,
            static fn (CachedAsset $asset): bool => $asset->getCorporationId() === $corporationId,
        ));
        $isInDivisions = static fn (CachedAsset $asset, array $divisionNumbers): bool => \in_array(
            $asset->getLocationFlag(),
            array_map(static fn (int $n): string => "CorpSAG{$n}", $divisionNumbers),
            true,
        );

        $this->cachedAssetRepository->method('findByCorporationId')->willReturnCallback(
            static fn (int $corporationId): array => $inCorporation($corporationId),
        );
        $this->cachedAssetRepository->method('findByCorporationAndDivision')->willReturnCallback(
            static fn (int $corporationId, string $divisionName): array => array_values(array_filter(
                $inCorporation($corporationId),
                static fn (CachedAsset $asset): bool => $asset->getDivisionName() === $divisionName,
            )),
        );
        $this->cachedAssetRepository->method('findByCorporationAndDivisions')->willReturnCallback(
            static fn (int $corporationId, array $divisionNumbers): array => array_values(array_filter(
                $inCorporation($corporationId),
                static fn (CachedAsset $asset): bool => $isInDivisions($asset, $divisionNumbers),
            )),
        );
        $this->cachedAssetRepository->method('findByCorporationDivisionNameAndFlags')->willReturnCallback(
            static fn (int $corporationId, string $divisionName, array $divisionNumbers): array => array_values(array_filter(
                $inCorporation($corporationId),
                static fn (CachedAsset $asset): bool => $asset->getDivisionName() === $divisionName
                    && $isInDivisions($asset, $divisionNumbers),
            )),
        );
    }

    /**
     * @param AssetItemResource[] $items
     * @return list<int>
     */
    private function itemIdsOf(array $items): array
    {
        return array_map(static fn (AssetItemResource $item): int => $item->itemId, $items);
    }
}
