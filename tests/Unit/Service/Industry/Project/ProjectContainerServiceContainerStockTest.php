<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Project;

use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\ProjectContainerService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectContainerService::class)]
#[Group('issue-708')]
final class ProjectContainerServiceContainerStockTest extends TestCase
{
    private const int CORPORATION_ID = 98_000_708;
    private const string CONTAINER_NAME = 'Build 708';
    private const int OFFICE_ITEM_ID = 1_070_800_000_001;
    private const int STATION_CONTAINER = 17_366;
    private const int HYDROGEN_FUEL_BLOCK = 4_246;
    private const int FERNITE_ALLOY = 16_656;

    private array $cachedAssets = [];
    private int $nextItemId = 1_070_800_001_000;
    private ProjectContainerService $service;

    protected function setUp(): void
    {
        $cachedAssetRepository = $this->createStub(CachedAssetRepository::class);
        $cachedAssetRepository->method('hasCorporationAssets')->willReturnCallback(fn (): bool => $this->cachedAssets !== []);
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

        $visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $visibilityRepository->method('findByCorporationId')->willReturn(
            (new CorpAssetVisibility())->setCorporationId(self::CORPORATION_ID)->setVisibleDivisions([2]),
        );

        $characterRepository = $this->createStub(CharacterRepository::class);
        $characterRepository->method('findWithCorpAssetsAccess')->willReturn($this->createStub(Character::class));

        $this->service = new ProjectContainerService(
            new VisibleCorporationAssetsFinder($cachedAssetRepository, $visibilityRepository),
            $this->createStub(IndustryProjectBomLineRepository::class),
            $cachedAssetRepository,
            $visibilityRepository,
            $characterRepository,
        );
    }

    public function testContainerStockAddsUpTheContentsOfTheNamedContainerAndItsSubContainers(): void
    {
        $build = $this->cache(self::STATION_CONTAINER, 1, self::OFFICE_ITEM_ID, 'CorpSAG2', self::CONTAINER_NAME);
        $this->cache(self::HYDROGEN_FUEL_BLOCK, 40, $build, 'Unlocked', null);
        $minerals = $this->cache(self::STATION_CONTAINER, 1, $build, 'Unlocked', 'Minerals');
        $this->cache(self::HYDROGEN_FUEL_BLOCK, 15, $minerals, 'Unlocked', null);
        $this->cache(self::FERNITE_ALLOY, 220, $minerals, 'Unlocked', null);
        $this->cache(self::FERNITE_ALLOY, 999, self::OFFICE_ITEM_ID, 'CorpSAG2', null);

        $stock = $this->service->containerStockOf($this->projectUsingContainer(self::CONTAINER_NAME));

        self::assertNull($stock->unknownReason);
        self::assertSame([self::HYDROGEN_FUEL_BLOCK => 55, self::FERNITE_ALLOY => 220], $stock->quantitiesByTypeId);
    }

    public function testContainerStockIsUnknownWhenNoVisibleContainerHasTheName(): void
    {
        $this->cache(self::STATION_CONTAINER, 1, self::OFFICE_ITEM_ID, 'CorpSAG2', 'Spare');

        $stock = $this->service->containerStockOf($this->projectUsingContainer(self::CONTAINER_NAME));

        self::assertSame(['container_not_found', []], [$stock->unknownReason, $stock->quantitiesByTypeId]);
    }

    public function testContainerStockHasNoContainerNameReasonForAProjectWithoutContainer(): void
    {
        $this->cache(self::STATION_CONTAINER, 1, self::OFFICE_ITEM_ID, 'CorpSAG2', self::CONTAINER_NAME);

        $stock = $this->service->containerStockOf($this->projectUsingContainer(null));

        self::assertSame(['no_container_name', []], [$stock->unknownReason, $stock->quantitiesByTypeId]);
    }

    private function projectUsingContainer(?string $containerName): IndustryProject
    {
        $owner = $this->createStub(User::class);
        $owner->method('getCorporationId')->willReturn(self::CORPORATION_ID);

        return (new IndustryProject())->setOwner($owner)->setContainerName($containerName);
    }

    private function cache(int $typeId, int $quantity, int $locationId, string $locationFlag, ?string $itemName): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName('Type '.$typeId)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName('Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->setLocationType('item')
            ->setLocationFlag($locationFlag)
            ->setItemName($itemName)
            ->setCorporationId(self::CORPORATION_ID)
            ->setIsCorporationAsset(true);

        return $itemId;
    }

    private function contentsOf(array $containerItemIds): array
    {
        $contents = [];
        while ($containerItemIds !== []) {
            $level = array_values(array_filter(
                $this->cachedAssets,
                static fn (CachedAsset $asset): bool => \in_array($asset->getLocationId(), $containerItemIds, true),
            ));
            $contents = [...$contents, ...$level];
            $containerItemIds = array_map(static fn (CachedAsset $asset): int => $asset->getItemId(), $level);
        }

        return $contents;
    }
}
