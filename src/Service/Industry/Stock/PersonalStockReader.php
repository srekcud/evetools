<?php

declare(strict_types=1);

namespace App\Service\Industry\Stock;

use App\Entity\CachedAsset;
use App\Entity\IndustryProjectContainer;
use App\Entity\PersonalStockContainer;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\PersonalStockContainerRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Industry\Project\AssetStation;
use App\Service\Industry\Project\AssetStations;
use App\Service\Industry\Project\ProjectContainerService;

final readonly class PersonalStockReader
{
    private const string REASON_STATION_UNRESOLVED = 'station_unresolved';

    public function __construct(
        private CachedAssetRepository $assetRepository,
        private PersonalStockContainerRepository $containerRepository,
        private VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private ProjectContainerService $containerService,
    ) {
    }

    public function of(User $user): PersonalStock
    {
        $quantities = $this->assetRepository->getAggregatedQuantitiesByUser($user);
        $locations = $this->assetRepository->getQuantitiesByUserWithLocations($user);
        $readings = [];

        foreach ($this->readingsOf($user) as $reading) {
            $readings[] = $reading['reading'];
            $station = $reading['station'];
            if (null === $station) {
                continue;
            }

            foreach ($reading['articles'] as $typeId => $quantity) {
                $quantities[$typeId] = isset($quantities[$typeId]) ? $quantities[$typeId] + $quantity : $quantity;
                $locations = self::withQuantityAt($locations, $typeId, $quantity, $station);
            }
        }

        return new PersonalStock($quantities, $locations, $readings);
    }

    /**
     * @return list<PersonalStockContainerReading>
     */
    public function containersOf(User $user): array
    {
        return array_map(static fn (array $reading): PersonalStockContainerReading => $reading['reading'], $this->readingsOf($user));
    }

    /**
     * @return list<array{reading: PersonalStockContainerReading, articles: array<int, int>, station: AssetStation|null}>
     */
    private function readingsOf(User $user): array
    {
        $declarations = $this->containerRepository->findByUser($user);
        if ([] === $declarations) {
            return [];
        }

        usort(
            $declarations,
            static fn (PersonalStockContainer $left, PersonalStockContainer $right): int => IndustryProjectContainer::keyOf($left->getName()) <=> IndustryProjectContainer::keyOf($right->getName()),
        );

        $corporationId = $user->getCorporationId();
        $unknownReason = $this->containerService->unavailabilityReasonOfUser($user);
        if (null === $unknownReason && null !== $corporationId && $this->containerService->areCorporationAssetsStale($this->assetRepository->lastCachedAtOfCorporationAssets($corporationId))) {
            $unknownReason = ProjectContainerService::STATUS_CORPORATION_ASSETS_STALE;
        }

        if (null !== $unknownReason || null === $corporationId) {
            return array_map(
                fn (PersonalStockContainer $declaration): array => self::unknown($declaration, $unknownReason ?? ProjectContainerService::REASON_OWNER_WITHOUT_CORPORATION),
                $declarations,
            );
        }

        $visibleAssets = $this->visibleAssetsFinder->findVisibleAssets($corporationId, null, $user);
        $visibleAssetsByItemId = [];
        foreach ($visibleAssets as $asset) {
            $visibleAssetsByItemId[$asset->getItemId()] = $asset;
        }

        $hiddenOrMissingItemIds = [];
        foreach ($declarations as $declaration) {
            if (!isset($visibleAssetsByItemId[$declaration->getItemId()])) {
                $hiddenOrMissingItemIds[] = $declaration->getItemId();
            }
        }
        $existingHiddenAssets = $this->assetRepository->findCorporationByItemIds($corporationId, $hiddenOrMissingItemIds);

        $stations = new AssetStations($visibleAssets, $this->assetRepository, $corporationId);

        $countedItemIds = [];
        $readings = [];
        foreach ($declarations as $declaration) {
            $readings[] = $this->readingOf(
                $declaration,
                $visibleAssetsByItemId[$declaration->getItemId()] ?? null,
                isset($existingHiddenAssets[$declaration->getItemId()]),
                $visibleAssets,
                $stations,
                $countedItemIds,
            );
        }

        return $readings;
    }

    /**
     * @param CachedAsset[] $visibleAssets
     * @param array<int, true> $countedItemIds
     *
     * @return array{reading: PersonalStockContainerReading, articles: array<int, int>, station: AssetStation|null}
     */
    private function readingOf(PersonalStockContainer $declaration, ?CachedAsset $asset, bool $existsOutOfSight, array $visibleAssets, AssetStations $stations, array &$countedItemIds): array
    {
        if (null === $asset) {
            return self::unknown($declaration, $existsOutOfSight ? ProjectContainerService::STATUS_CONTAINER_IN_HIDDEN_DIVISION : ProjectContainerService::STATUS_CONTAINER_NOT_FOUND);
        }

        $station = $stations->locationOf($asset);
        if (null === $station) {
            return self::unknown($declaration, self::REASON_STATION_UNRESOLVED);
        }

        $articles = $this->articlesOf($this->containerService->contentsOfContainers($visibleAssets, [$asset->getItemId()]), $countedItemIds);

        return [
            'reading' => new PersonalStockContainerReading(
                $declaration->getId()->toRfc4122(),
                $declaration->getItemId(),
                $asset->getItemName() ?? $declaration->getName(),
                $asset->getDivisionName() ?? $declaration->getDivisionName(),
                $station->locationId,
                $station->name,
                PersonalStockContainerReading::STATUS_KNOWN,
                null,
                \count($articles),
            ),
            'articles' => $articles,
            'station' => $station,
        ];
    }

    /**
     * @return array{reading: PersonalStockContainerReading, articles: array<int, int>, station: AssetStation|null}
     */
    private static function unknown(PersonalStockContainer $declaration, string $reason): array
    {
        return [
            'reading' => new PersonalStockContainerReading(
                $declaration->getId()->toRfc4122(),
                $declaration->getItemId(),
                $declaration->getName(),
                $declaration->getDivisionName(),
                $declaration->getStationId(),
                $declaration->getStationName(),
                PersonalStockContainerReading::STATUS_UNKNOWN,
                $reason,
                null,
            ),
            'articles' => [],
            'station' => null,
        ];
    }

    /**
     * @param list<CachedAsset> $contents
     * @param array<int, true> $countedItemIds
     *
     * @return array<int, int>
     */
    private function articlesOf(array $contents, array &$countedItemIds): array
    {
        $parentItemIds = [];
        foreach ($contents as $content) {
            $parentItemIds[$content->getLocationId()] = true;
        }

        $quantitiesByTypeId = [];
        foreach ($contents as $content) {
            if (isset($parentItemIds[$content->getItemId()]) || isset($countedItemIds[$content->getItemId()])) {
                continue;
            }

            $countedItemIds[$content->getItemId()] = true;
            $typeId = $content->getTypeId();
            $quantitiesByTypeId[$typeId] = isset($quantitiesByTypeId[$typeId]) ? $quantitiesByTypeId[$typeId] + $content->getQuantity() : $content->getQuantity();
        }

        return $quantitiesByTypeId;
    }

    /**
     * @param array<int, array{total: int, locations: list<array{locationId: int, locationName: string, systemName: string|null, quantity: int}>}> $locations
     *
     * @return array<int, array{total: int, locations: list<array{locationId: int, locationName: string, systemName: string|null, quantity: int}>}>
     */
    private static function withQuantityAt(array $locations, int $typeId, int $quantity, AssetStation $station): array
    {
        $entry = $locations[$typeId] ?? ['total' => 0, 'locations' => []];
        $entry['total'] += $quantity;

        $merged = false;
        foreach ($entry['locations'] as $index => $location) {
            if ($location['locationId'] === $station->locationId) {
                $entry['locations'][$index]['quantity'] += $quantity;
                $merged = true;
                break;
            }
        }

        if (!$merged) {
            $entry['locations'][] = [
                'locationId' => $station->locationId,
                'locationName' => $station->name,
                'systemName' => $station->solarSystemName,
                'quantity' => $quantity,
            ];
        }

        usort($entry['locations'], static fn (array $left, array $right): int => $right['quantity'] <=> $left['quantity']);
        $locations[$typeId] = $entry;

        return $locations;
    }
}
