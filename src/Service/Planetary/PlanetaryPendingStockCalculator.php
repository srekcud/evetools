<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Entity\PlanetaryRoute;
use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;
use App\Service\TypeNameResolver;
use Symfony\Component\Clock\ClockInterface;

/**
 * @phpstan-type SchematicMap array<int, array{inputs: array<int, int>, output: array{typeId: int, quantity: int}|null}>
 * @phpstan-type Factory array{inputs: array<int, float>, output: array{typeId: int, daily: float}|null}
 * @phpstan-type Content array{typeId: int, typeName: string|null, stock: float, projectedStock: float|null, volumePerUnit: float|null}
 * @phpstan-type StorageRow array{
 *     colonyId: string|null,
 *     planetId: int,
 *     planetName: string|null,
 *     solarSystemName: string|null,
 *     characterId: int,
 *     characterName: string,
 *     pinId: int,
 *     typeId: int,
 *     typeName: string|null,
 *     kind: string,
 *     capacityM3: float|null,
 *     usedVolumeM3: float|null,
 *     contents: list<Content>,
 *     lastViewAt: string,
 *     dataAgeSeconds: int,
 *     dataStale: bool,
 * }
 */
class PlanetaryPendingStockCalculator
{
    private const SECONDS_PER_DAY = 86400;
    private const STALE_DATA_AFTER_SECONDS = 86400;
    private const SPACEPORTS_GROUP_ID = 1030;
    private const STORAGE_FACILITIES_GROUP_ID = 1029;
    private const STORAGE_KIND_BY_GROUP_ID = [
        self::SPACEPORTS_GROUP_ID => 'launchpad',
        self::STORAGE_FACILITIES_GROUP_ID => 'storage',
    ];
    private const DESTINATION_COLONY = 'colony';
    private const DESTINATION_HOME = 'home';

    public function __construct(
        private readonly PlanetSchematicRepository $schematicRepository,
        private readonly PlanetSchematicTypeRepository $schematicTypeRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly ClockInterface $clock,
        private readonly ExtractorYieldCalculator $extractorYield,
    ) {
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return array{storages: list<StorageRow>, pendingOutputs: list<array<string, mixed>>, complete: bool}
     */
    public function calculate(array $colonies): array
    {
        $colonies = array_values($colonies);
        $schematicMap = $this->schematicTypeRepository->getSchematicMap();
        $sdeTypes = $this->loadSdeTypes($colonies, $schematicMap);
        $now = $this->clock->now();

        $complete = true;
        $factoriesByColony = [];
        foreach ($colonies as $index => $colony) {
            $analysis = $this->analyseFactories($colony, $schematicMap);
            $factoriesByColony[$index] = $analysis['factories'];
            $complete = $complete && $analysis['complete'];
        }
        $consumers = $this->consumingColonies($factoriesByColony);

        $storages = [];
        $pendingOutputs = [];
        foreach ($colonies as $index => $colony) {
            $colonyStorages = $this->storageRows($colony, $factoriesByColony[$index], $sdeTypes, $now);
            array_push($storages, ...$colonyStorages);
            array_push($pendingOutputs, ...$this->pendingRows($colony, $colonyStorages, $factoriesByColony[$index], $this->consumersOf($index, $colonies, $consumers), $sdeTypes, $now));
        }

        usort($storages, static fn (array $first, array $second): int => [$first['planetName'] ?? '', $first['pinId']] <=> [$second['planetName'] ?? '', $second['pinId']]);
        usort($pendingOutputs, static fn (array $first, array $second): int => [$first['typeId'], $first['planetName'] ?? ''] <=> [$second['typeId'], $second['planetName'] ?? '']);

        return ['storages' => $storages, 'pendingOutputs' => $pendingOutputs, 'complete' => $complete];
    }

    /**
     * @param SchematicMap $schematicMap
     * @return array{factories: array<int, Factory>, complete: bool}
     */
    private function analyseFactories(PlanetaryColony $colony, array $schematicMap): array
    {
        $factories = [];
        $complete = true;

        foreach ($colony->getPins() as $pin) {
            if (!$pin->isFactory()) {
                continue;
            }

            $schematicId = $pin->getSchematicId();
            $schematic = $schematicId === null ? null : $this->schematicRepository->findBySchematicId($schematicId);
            if ($schematicId === null || !isset($schematicMap[$schematicId]) || $schematic === null || $schematic->getCycleTime() <= 0) {
                $complete = false;

                continue;
            }

            $cyclesPerDay = self::SECONDS_PER_DAY / (float) $schematic->getCycleTime();
            $inputs = [];
            foreach ($schematicMap[$schematicId]['inputs'] as $inputTypeId => $inputQuantity) {
                $inputs[$inputTypeId] = $inputQuantity * $cyclesPerDay;
            }
            $output = $schematicMap[$schematicId]['output'];

            $factories[$pin->getPinId()] = [
                'inputs' => $inputs,
                'output' => $output === null ? null : ['typeId' => $output['typeId'], 'daily' => $output['quantity'] * $cyclesPerDay],
            ];
        }

        return ['factories' => $factories, 'complete' => $complete];
    }

    /**
     * @param array<int, array<int, Factory>> $factoriesByColony
     * @return array<int, array<int, true>> typeId => colony index => true
     */
    private function consumingColonies(array $factoriesByColony): array
    {
        $consumers = [];
        foreach ($factoriesByColony as $index => $factories) {
            foreach ($factories as $factory) {
                foreach (array_keys($factory['inputs']) as $typeId) {
                    $consumers[$typeId][$index] = true;
                }
            }
        }

        return $consumers;
    }

    /**
     * @param PlanetaryColony[]                $colonies
     * @param array<int, array<int, true>>     $consumers
     * @return array<int, list<array{colonyId: string|null, planetName: string|null}>> typeId => other colonies consuming it
     */
    private function consumersOf(int $producerIndex, array $colonies, array $consumers): array
    {
        $byType = [];
        foreach ($consumers as $typeId => $indexes) {
            $others = [];
            foreach (array_keys($indexes) as $index) {
                if ($index !== $producerIndex) {
                    $others[] = ['colonyId' => $colonies[$index]->getId()?->toRfc4122(), 'planetName' => $colonies[$index]->getPlanetName()];
                }
            }
            usort($others, static fn (array $first, array $second): int => ($first['planetName'] ?? '') <=> ($second['planetName'] ?? ''));
            $byType[$typeId] = $others;
        }

        return $byType;
    }

    /**
     * @param array<int, Factory> $factories
     * @param array<int, InvType> $sdeTypes
     * @return list<StorageRow>
     */
    private function storageRows(PlanetaryColony $colony, array $factories, array $sdeTypes, \DateTimeImmutable $now): array
    {
        $pins = [];
        $storageKinds = [];
        foreach ($colony->getPins() as $pin) {
            $pins[$pin->getPinId()] = $pin;
            $kind = $this->storageKind($pin, $sdeTypes);
            if ($kind !== null) {
                $storageKinds[$pin->getPinId()] = $kind;
            }
        }
        if ($storageKinds === []) {
            return [];
        }

        $ageSeconds = $this->dataAgeSeconds($colony, $now);
        $outflows = $this->outflowsByStorage($colony, $factories, $storageKinds);
        $entries = $this->entriesByStorage($colony, $pins, $factories, $storageKinds, $now);

        $rows = [];
        foreach ($storageKinds as $pinId => $kind) {
            $rows[] = $this->storageRow($colony, $pins[$pinId], $kind, $outflows[$pinId], $entries[$pinId], $sdeTypes, $ageSeconds);
        }

        return $rows;
    }

    /**
     * @param array<int, Factory> $factories
     * @param array<int, string>  $storageKinds
     * @return array<int, array<int, float>> storage pinId => typeId => daily consumption
     */
    private function outflowsByStorage(PlanetaryColony $colony, array $factories, array $storageKinds): array
    {
        $outflows = array_fill_keys(array_keys($storageKinds), []);

        $routesByFlow = [];
        foreach ($colony->getRoutes() as $route) {
            $factory = $factories[$route->getDestinationPinId()] ?? null;
            if ($factory !== null && array_key_exists($route->getContentTypeId(), $factory['inputs'])) {
                $routesByFlow[$route->getDestinationPinId().':'.$route->getContentTypeId()][] = $route;
            }
        }

        foreach ($routesByFlow as $routes) {
            $dailyNeed = $factories[$routes[0]->getDestinationPinId()]['inputs'][$routes[0]->getContentTypeId()];
            foreach ($this->shares($routes) as $position => $share) {
                $sourcePinId = $routes[$position]->getSourcePinId();
                if (array_key_exists($sourcePinId, $outflows)) {
                    $typeId = $routes[$position]->getContentTypeId();
                    $outflows[$sourcePinId][$typeId] = $this->sumOf($outflows[$sourcePinId], $typeId) + $dailyNeed * $share;
                }
            }
        }

        return $outflows;
    }

    /**
     * @param array<int, PlanetaryPin> $pins
     * @param array<int, Factory>      $factories
     * @param array<int, string>       $storageKinds
     * @return array<int, array<int, float|null>> storage pinId => typeId => quantity received since the last view, null when unknown
     */
    private function entriesByStorage(PlanetaryColony $colony, array $pins, array $factories, array $storageKinds, \DateTimeImmutable $now): array
    {
        $entries = array_fill_keys(array_keys($storageKinds), []);

        $routesBySource = [];
        foreach ($colony->getRoutes() as $route) {
            $routesBySource[$route->getSourcePinId().':'.$route->getContentTypeId()][] = $route;
        }

        foreach ($routesBySource as $routes) {
            $sourcePinId = $routes[0]->getSourcePinId();
            $typeId = $routes[0]->getContentTypeId();
            if (!isset($pins[$sourcePinId]) || $this->producedTypeId($pins[$sourcePinId], $factories) !== $typeId) {
                continue;
            }

            $produced = $this->producedSince($colony, $pins[$sourcePinId], $factories, $now);
            foreach ($this->shares($routes) as $position => $share) {
                $destinationPinId = $routes[$position]->getDestinationPinId();
                if (!array_key_exists($destinationPinId, $entries)) {
                    continue;
                }
                $received = $produced === null ? null : $produced * $share;
                $entries[$destinationPinId][$typeId] = array_key_exists($typeId, $entries[$destinationPinId])
                    ? $this->addNullable($entries[$destinationPinId][$typeId], $received)
                    : $received;
            }
        }

        return $entries;
    }

    /**
     * @param list<PlanetaryRoute> $routes
     * @return list<float> weight of each route among the routes, in the same order
     */
    private function shares(array $routes): array
    {
        $totalWeight = array_sum(array_map(static fn (PlanetaryRoute $route): float => $route->getQuantity(), $routes));

        return array_map(
            static fn (PlanetaryRoute $route): float => $totalWeight > 0 ? $route->getQuantity() / $totalWeight : 1 / count($routes),
            $routes,
        );
    }

    /**
     * @param array<int, Factory> $factories
     */
    private function producedTypeId(PlanetaryPin $pin, array $factories): ?int
    {
        if ($pin->isExtractor()) {
            return $pin->getExtractorProductTypeId();
        }

        return isset($factories[$pin->getPinId()]) ? $factories[$pin->getPinId()]['output']['typeId'] ?? null : null;
    }

    /**
     * @param array<int, Factory> $factories
     */
    private function producedSince(PlanetaryColony $colony, PlanetaryPin $source, array $factories, \DateTimeImmutable $now): ?float
    {
        if (!$source->isExtractor()) {
            $output = $factories[$source->getPinId()]['output'];

            return $output === null ? null : $output['daily'] * $this->dataAgeSeconds($colony, $now) / self::SECONDS_PER_DAY;
        }

        $cycleTime = $source->getExtractorCycleTime();
        $quantityPerCycle = $source->getExtractorQtyPerCycle();
        if ($cycleTime === null || $quantityPerCycle === null) {
            return null;
        }

        return $this->extractorYield->yieldBetween($quantityPerCycle, $cycleTime, $source->getInstallTime(), $source->getExpiryTime(), $colony->getLastUpdate(), $now);
    }

    /**
     * @param array<int, float>       $outflow
     * @param array<int, float|null>  $entries
     * @param array<int, InvType>     $sdeTypes
     * @return StorageRow
     */
    private function storageRow(PlanetaryColony $colony, PlanetaryPin $pin, string $kind, array $outflow, array $entries, array $sdeTypes, int $ageSeconds): array
    {
        $stock = [];
        foreach ($pin->getContents() ?? [] as $content) {
            if ($content['amount'] > 0) {
                $stock[$content['type_id']] = $this->sumOf($stock, $content['type_id']) + (float) $content['amount'];
            }
        }

        $base = [];
        foreach (array_keys($stock + $entries) as $typeId) {
            $base[$typeId] = max(0.0, $this->sumOf($stock, $typeId) - $this->sumOf($outflow, $typeId) * $ageSeconds / self::SECONDS_PER_DAY);
        }
        ksort($base);

        $capacity = isset($sdeTypes[$pin->getTypeId()]) ? $sdeTypes[$pin->getTypeId()]->getCapacity() : null;
        $projected = $this->projectedStock($base, $entries, $capacity, $sdeTypes);

        $contents = [];
        foreach ($projected as $typeId => $projectedStock) {
            $contents[] = [
                'typeId' => $typeId,
                'typeName' => $this->typeNameResolver->findName($typeId),
                'stock' => $this->sumOf($stock, $typeId),
                'projectedStock' => $projectedStock,
                'volumePerUnit' => $this->volumeOf($sdeTypes, $typeId),
            ];
        }

        return [
            'colonyId' => $colony->getId()?->toRfc4122(),
            'planetId' => $colony->getPlanetId(),
            'planetName' => $colony->getPlanetName(),
            'solarSystemName' => $colony->getSolarSystemName(),
            'characterId' => $colony->getCharacter()->getEveCharacterId(),
            'characterName' => $colony->getCharacter()->getName(),
            'pinId' => $pin->getPinId(),
            'typeId' => $pin->getTypeId(),
            'typeName' => $this->typeNameResolver->findName($pin->getTypeId()),
            'kind' => $kind,
            'capacityM3' => $capacity,
            'usedVolumeM3' => $this->volumeOfQuantities($sdeTypes, $projected),
            'contents' => $contents,
        ] + $this->dataAgeFields($colony, $ageSeconds);
    }

    /**
     * @param array<int, float>      $base
     * @param array<int, float|null> $entries
     * @param array<int, InvType>    $sdeTypes
     * @return array<int, float|null>
     */
    private function projectedStock(array $base, array $entries, ?float $capacity, array $sdeTypes): array
    {
        $entryScale = $this->entryScale($base, $entries, $capacity, $sdeTypes);

        $projected = [];
        foreach ($base as $typeId => $baseQuantity) {
            if (!array_key_exists($typeId, $entries)) {
                $projected[$typeId] = $baseQuantity;
            } elseif ($entryScale === null || $entries[$typeId] === null) {
                $projected[$typeId] = null;
            } else {
                $projected[$typeId] = $baseQuantity + $entries[$typeId] * $entryScale;
            }
        }

        return $projected;
    }

    /**
     * @param array<int, float>      $base
     * @param array<int, float|null> $entries
     * @param array<int, InvType>    $sdeTypes
     * @return float|null factor applied to the entries so that they fit the room left, null when it cannot be known
     */
    private function entryScale(array $base, array $entries, ?float $capacity, array $sdeTypes): ?float
    {
        if (array_filter($entries, static fn (?float $entry): bool => $entry !== 0.0) === []) {
            return 1.0;
        }

        $baseVolume = $this->volumeOfQuantities($sdeTypes, $base);
        $entriesVolume = $this->volumeOfQuantities($sdeTypes, $entries);
        if ($capacity === null || $baseVolume === null || $entriesVolume === null) {
            return null;
        }

        $room = max(0.0, $capacity - $baseVolume);

        return $entriesVolume > 0 ? min(1.0, $room / $entriesVolume) : 1.0;
    }

    /**
     * @param PlanetaryColony           $colony
     * @param list<StorageRow>          $storages
     * @param array<int, Factory>       $factories
     * @param array<int, list<array{colonyId: string|null, planetName: string|null}>> $consumersByType
     * @param array<int, InvType>       $sdeTypes
     * @return list<array<string, mixed>>
     */
    private function pendingRows(PlanetaryColony $colony, array $storages, array $factories, array $consumersByType, array $sdeTypes, \DateTimeImmutable $now): array
    {
        $producedTypeIds = [];
        $consumedTypeIds = [];
        foreach ($colony->getPins() as $pin) {
            $producedTypeId = $this->producedTypeId($pin, $factories);
            if ($producedTypeId !== null) {
                $producedTypeIds[$producedTypeId] = true;
            }
        }
        foreach ($factories as $factory) {
            $consumedTypeIds += array_fill_keys(array_keys($factory['inputs']), true);
        }

        $ageSeconds = $this->dataAgeSeconds($colony, $now);
        $rows = [];
        foreach (array_keys(array_diff_key($producedTypeIds, $consumedTypeIds)) as $typeId) {
            $quantity = 0.0;
            $pinIds = [];
            foreach ($storages as $storage) {
                foreach ($storage['contents'] as $content) {
                    if ($content['typeId'] !== $typeId || $content['projectedStock'] === 0.0) {
                        continue;
                    }
                    $quantity = $this->addNullable($quantity, $content['projectedStock']);
                    $pinIds[] = $storage['pinId'];
                }
            }
            if ($quantity === 0.0) {
                continue;
            }

            $volumePerUnit = $this->volumeOf($sdeTypes, $typeId);
            $consumers = $consumersByType[$typeId] ?? [];
            $rows[] = [
                'colonyId' => $colony->getId()?->toRfc4122(),
                'planetId' => $colony->getPlanetId(),
                'planetName' => $colony->getPlanetName(),
                'solarSystemName' => $colony->getSolarSystemName(),
                'characterId' => $colony->getCharacter()->getEveCharacterId(),
                'characterName' => $colony->getCharacter()->getName(),
                'typeId' => $typeId,
                'typeName' => $this->typeNameResolver->findName($typeId),
                'quantity' => $quantity,
                'volumePerUnit' => $volumePerUnit,
                'volumeM3' => $quantity === null || $volumePerUnit === null ? null : $quantity * $volumePerUnit,
                'pinIds' => $pinIds,
                'consumers' => $consumers,
                'destination' => $consumers === [] ? self::DESTINATION_HOME : self::DESTINATION_COLONY,
            ] + $this->dataAgeFields($colony, $ageSeconds);
        }

        return $rows;
    }

    /**
     * @param array<int, InvType> $sdeTypes
     */
    private function storageKind(PlanetaryPin $pin, array $sdeTypes): ?string
    {
        if (!isset($sdeTypes[$pin->getTypeId()])) {
            return null;
        }

        return self::STORAGE_KIND_BY_GROUP_ID[$sdeTypes[$pin->getTypeId()]->getGroup()->getGroupId()] ?? null;
    }

    private function dataAgeSeconds(PlanetaryColony $colony, \DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $colony->getLastUpdate()->getTimestamp());
    }

    /**
     * @return array{lastViewAt: string, dataAgeSeconds: int, dataStale: bool}
     */
    private function dataAgeFields(PlanetaryColony $colony, int $ageSeconds): array
    {
        return [
            'lastViewAt' => $colony->getLastUpdate()->format('c'),
            'dataAgeSeconds' => $ageSeconds,
            'dataStale' => $ageSeconds > self::STALE_DATA_AFTER_SECONDS,
        ];
    }

    /**
     * @param array<int, InvType> $sdeTypes
     */
    private function volumeOf(array $sdeTypes, int $typeId): ?float
    {
        return isset($sdeTypes[$typeId]) ? $sdeTypes[$typeId]->getVolume() : null;
    }

    /**
     * @param array<int, InvType>    $sdeTypes
     * @param array<int, float|null> $quantities
     */
    private function volumeOfQuantities(array $sdeTypes, array $quantities): ?float
    {
        $total = 0.0;
        foreach ($quantities as $typeId => $quantity) {
            $volume = $this->volumeOf($sdeTypes, $typeId);
            if ($quantity === null || $volume === null) {
                return null;
            }
            $total += $quantity * $volume;
        }

        return $total;
    }

    /**
     * @param array<int, float> $quantities
     */
    private function sumOf(array $quantities, int $typeId): float
    {
        return array_key_exists($typeId, $quantities) ? $quantities[$typeId] : 0.0;
    }

    private function addNullable(?float $first, ?float $second): ?float
    {
        return $first === null || $second === null ? null : $first + $second;
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @param SchematicMap      $schematicMap
     * @return array<int, InvType>
     */
    private function loadSdeTypes(array $colonies, array $schematicMap): array
    {
        $typeIds = [];
        foreach ($colonies as $colony) {
            foreach ($colony->getPins() as $pin) {
                $typeIds[$pin->getTypeId()] = true;
                if ($pin->getExtractorProductTypeId() !== null) {
                    $typeIds[$pin->getExtractorProductTypeId()] = true;
                }
                foreach ($pin->getContents() ?? [] as $content) {
                    $typeIds[$content['type_id']] = true;
                }
            }
            foreach ($colony->getRoutes() as $route) {
                $typeIds[$route->getContentTypeId()] = true;
            }
        }
        foreach ($schematicMap as $schematic) {
            foreach (array_keys($schematic['inputs']) as $inputTypeId) {
                $typeIds[$inputTypeId] = true;
            }
            if ($schematic['output'] !== null) {
                $typeIds[$schematic['output']['typeId']] = true;
            }
        }

        return $typeIds === [] ? [] : $this->invTypeRepository->findByTypeIds(array_keys($typeIds));
    }
}
