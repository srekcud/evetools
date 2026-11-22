<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry\Stock;

use App\Constant\EveConstants;
use App\Entity\CachedAsset;
use App\Entity\Character;
use App\Entity\CorpAssetVisibility;
use App\Entity\PersonalStockContainer;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\PersonalStockContainerRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\ProjectContainerService;
use App\Service\Industry\Project\StartedJobConsumption;
use App\Service\Industry\Project\StartedJobConsumptionReader;
use App\Service\Industry\Stock\PersonalStockReader;
use App\Tests\Unit\Service\ESI\BuildsCorporationDirectorAccess;

trait StagesCorporationAssets
{
    use BuildsCorporationDirectorAccess;

    private const int STAGED_CORPORATION_ID = 98_132_400;
    private const int STAGED_CONTAINER_TYPE_ID = 17_366;

    private array $cachedAssets = [];
    private int $nextItemId = 1_132_400_001_000;
    private array $visibleDivisions = [2];
    private ?\DateTimeImmutable $assetsSyncedAt;
    private array $hangarQuantities = [];
    private array $hangarLocations = [];
    private array $declaredContainers = [];
    private CachedAssetRepository $corporationAssetRepository;
    private VisibleCorporationAssetsFinder $visibleAssetsFinder;
    private ProjectContainerService $projectContainerService;
    private PersonalStockReader $personalStockReader;

    private function stageCorporationAssets(): void
    {
        $this->assetsSyncedAt = new \DateTimeImmutable('-1 hour');

        $assetRepository = $this->createStub(CachedAssetRepository::class);
        $assetRepository->method('hasCorporationAssets')->willReturnCallback(fn (): bool => $this->cachedAssets !== []);
        $assetRepository->method('lastCachedAtOfCorporationAssets')->willReturnCallback(fn (): ?\DateTimeImmutable => $this->assetsSyncedAt);
        $assetRepository->method('findByCorporationAndDivisions')->willReturnCallback(
            fn (int $corporationId, array $divisionNumbers): array => array_values(array_filter(
                $this->cachedAssets,
                static fn (CachedAsset $asset): bool => \in_array(
                    $asset->getLocationFlag(),
                    array_map(static fn (int $divisionNumber): string => 'CorpSAG'.$divisionNumber, $divisionNumbers),
                    true,
                ),
            )),
        );
        $assetRepository->method('findContentsOfContainers')->willReturnCallback(
            fn (int $corporationId, array $containerItemIds): array => $this->contentsOf($containerItemIds),
        );
        $assetRepository->method('findCorporationByItemIds')->willReturnCallback(
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
        $assetRepository->method('getAggregatedQuantitiesByUser')->willReturnCallback(fn (): array => $this->hangarQuantities);
        $assetRepository->method('getQuantitiesByUserWithLocations')->willReturnCallback(fn (): array => $this->hangarLocations);
        $this->corporationAssetRepository = $assetRepository;

        $visibilityRepository = $this->createStub(CorpAssetVisibilityRepository::class);
        $visibilityRepository->method('findByCorporationId')->willReturnCallback(
            fn (): CorpAssetVisibility => (new CorpAssetVisibility())->setCorporationId(self::STAGED_CORPORATION_ID)->setVisibleDivisions($this->visibleDivisions),
        );

        $consumptionReader = $this->createStub(StartedJobConsumptionReader::class);
        $consumptionReader->method('of')->willReturn(StartedJobConsumption::none());

        $this->visibleAssetsFinder = new VisibleCorporationAssetsFinder($assetRepository, $visibilityRepository);
        $this->projectContainerService = new ProjectContainerService(
            $this->visibleAssetsFinder,
            $this->createStub(IndustryProjectBomLineRepository::class),
            $assetRepository,
            $visibilityRepository,
            $this->corporationDirectorAccess(),
            $consumptionReader,
        );

        $declaredContainers = $this->createStub(PersonalStockContainerRepository::class);
        $declaredContainers->method('findByUser')->willReturnCallback(fn (): array => $this->declaredContainers);
        $this->personalStockReader = new PersonalStockReader(
            $assetRepository,
            $declaredContainers,
            $this->visibleAssetsFinder,
            $this->projectContainerService,
        );
    }

    private function connectDirector(): void
    {
        $this->corporationMember(self::STAGED_CORPORATION_ID, ['Director']);
    }

    private function userInTheCorporation(): User
    {
        $character = (new Character())
            ->setEveCharacterId(2_132_400_001)
            ->setName('Pilot')
            ->setCorporationId(self::STAGED_CORPORATION_ID);
        $user = new User();
        $user->addCharacter($character);
        $user->setMainCharacter($character);

        return $user;
    }

    private function declareContainer(User $user, string $name, int $itemId, ?string $divisionName = 'Division 2', ?int $stationId = 100, ?string $stationName = 'Jita IV'): PersonalStockContainer
    {
        $declaration = new PersonalStockContainer($user, $itemId, $name, $divisionName, $stationId, $stationName);
        $this->declaredContainers[] = $declaration;

        return $declaration;
    }

    private function cacheOffice(int $stationId, string $stationName = 'Jita IV', string $systemName = 'Jita', string $locationType = 'station'): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = $this->stagedAsset($itemId, 27, 1, $stationId, $locationType, EveConstants::OFFICE_LOCATION_FLAG, null, null)
            ->setLocationName($stationName)
            ->setSolarSystemName($systemName);

        return $itemId;
    }

    private function cacheContainer(string $name, int $locationId, string $locationFlag = 'CorpSAG2', ?bool $isSingleton = true): int
    {
        $itemId = $this->cacheItem(self::STAGED_CONTAINER_TYPE_ID, 1, $locationId, 'Station Container', $name, $locationFlag);
        $this->assetOf($itemId)->setIsSingleton($isSingleton)->setDivisionName('Division '.substr($locationFlag, -1));

        return $itemId;
    }

    private function cacheItem(int $typeId, int $quantity, int $locationId, ?string $typeName, ?string $itemName = null, string $locationFlag = 'Unlocked'): int
    {
        $itemId = $this->nextItemId++;
        $this->cachedAssets[] = $this->stagedAsset($itemId, $typeId, $quantity, $locationId, 'item', $locationFlag, $itemName, $typeName);

        return $itemId;
    }

    private function removeFromAssets(int $itemId): void
    {
        $this->cachedAssets = array_values(array_filter(
            $this->cachedAssets,
            static fn (CachedAsset $asset): bool => $asset->getItemId() !== $itemId,
        ));
    }

    private function assetOf(int $itemId): CachedAsset
    {
        foreach ($this->cachedAssets as $asset) {
            if ($asset->getItemId() === $itemId) {
                return $asset;
            }
        }

        throw new \LogicException('No staged asset has this item id.');
    }

    private function stagedAsset(int $itemId, int $typeId, int $quantity, int $locationId, string $locationType, string $locationFlag, ?string $itemName, ?string $typeName): CachedAsset
    {
        return (new CachedAsset())
            ->setItemId($itemId)
            ->setTypeId($typeId)
            ->setTypeName($typeName)
            ->setQuantity($quantity)
            ->setLocationId($locationId)
            ->setLocationName('Unresolved location')
            ->setLocationType($locationType)
            ->setLocationFlag($locationFlag)
            ->setItemName($itemName)
            ->setCorporationId(self::STAGED_CORPORATION_ID)
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
