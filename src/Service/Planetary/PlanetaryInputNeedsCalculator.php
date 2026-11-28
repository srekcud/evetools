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
 * @phpstan-type ColonyFlows array{
 *     factoryInputsByPin: array<int, array<int, float>>,
 *     required: array<int, float>,
 *     factoryOutput: array<int, float>,
 *     extraction: array<int, float>,
 *     unknownExtraction: array<int, true>,
 *     hasFactory: bool,
 *     complete: bool,
 * }
 */
class PlanetaryInputNeedsCalculator
{
    private const SECONDS_PER_DAY = 86400;
    private const DAYS_PER_WEEK = 7;
    private const SPACEPORTS_GROUP_ID = 1030;
    private const STALE_DATA_AFTER_SECONDS = 86400;

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
     * @return array{colonies: list<array<string, mixed>>, launchpads: list<array<string, mixed>>, extractions: list<array<string, mixed>>, weekly: list<array<string, mixed>>, complete: bool}
     */
    public function calculate(array $colonies): array
    {
        $schematicMap = $this->schematicTypeRepository->getSchematicMap();
        $sdeTypes = $this->loadSdeTypes($colonies, $schematicMap);

        $now = $this->clock->now();
        $colonyRows = [];
        $launchpadRows = [];
        $extractionRows = [];
        $consumption = [];
        $extraction = [];
        $unknownExtraction = [];
        $factoryOutput = [];
        $complete = true;

        foreach ($colonies as $colony) {
            $flows = $this->analyseColony($colony, $schematicMap, $now);

            if ($flows['hasFactory']) {
                $colonyRows[] = $this->colonyRow($colony, $flows, $sdeTypes, $now);
            }
            foreach ($this->launchpadRows($colony, $flows, $sdeTypes, $now) as $row) {
                $launchpadRows[] = $row;
            }
            $extractionRow = $this->extractionRow($colony, $now);
            if ($extractionRow !== null) {
                $extractionRows[] = $extractionRow;
            }

            $complete = $complete && $flows['complete'];
            $this->addTo($consumption, $flows['required']);
            $this->addTo($extraction, $flows['extraction']);
            $this->addTo($factoryOutput, $flows['factoryOutput']);
            $unknownExtraction += $flows['unknownExtraction'];
        }

        return [
            'colonies' => $colonyRows,
            'launchpads' => $launchpadRows,
            'extractions' => $extractionRows,
            'weekly' => $this->weeklyRows($consumption, $extraction, $unknownExtraction, $factoryOutput),
            'complete' => $complete,
        ];
    }

    /**
     * @return list<array<string, mixed>> the colony's input needs, each with the `factoryCount` of factories consuming the type
     */
    public function factoryNeedsOf(PlanetaryColony $colony): array
    {
        $schematicMap = $this->schematicTypeRepository->getSchematicMap();
        $now = $this->clock->now();
        $flows = $this->analyseColony($colony, $schematicMap, $now);
        if (!$flows['hasFactory']) {
            return [];
        }

        $factoryCounts = [];
        foreach ($flows['factoryInputsByPin'] as $inputs) {
            foreach (array_keys($inputs) as $typeId) {
                if (!isset($factoryCounts[$typeId])) {
                    $factoryCounts[$typeId] = 0;
                }
                ++$factoryCounts[$typeId];
            }
        }

        $needs = [];
        foreach ($this->colonyRow($colony, $flows, $this->loadSdeTypes([$colony], $schematicMap), $now)['needs'] as $need) {
            $needs[] = $need + ['factoryCount' => $factoryCounts[$need['typeId']]];
        }

        return $needs;
    }

    /**
     * @param SchematicMap $schematicMap
     * @return ColonyFlows
     */
    private function analyseColony(PlanetaryColony $colony, array $schematicMap, \DateTimeImmutable $now): array
    {
        $flows = [
            'factoryInputsByPin' => [],
            'required' => [],
            'factoryOutput' => [],
            'extraction' => [],
            'unknownExtraction' => [],
            'hasFactory' => false,
            'complete' => true,
        ];

        foreach ($colony->getPins() as $pin) {
            if ($pin->isExtractor()) {
                $this->addExtractor($flows, $pin, $now);
            } elseif ($pin->isFactory()) {
                $this->addFactory($flows, $pin, $schematicMap);
            }
        }

        return $flows;
    }

    /**
     * @param ColonyFlows $flows
     */
    private function addExtractor(array &$flows, PlanetaryPin $pin, \DateTimeImmutable $now): void
    {
        $productTypeId = $pin->getExtractorProductTypeId();
        if ($productTypeId === null) {
            return;
        }

        $cycleTime = $pin->getExtractorCycleTime();
        $quantityPerCycle = $pin->getExtractorQtyPerCycle();
        if ($cycleTime === null || $cycleTime <= 0 || $quantityPerCycle === null) {
            $flows['unknownExtraction'][$productTypeId] = true;

            return;
        }

        $expiry = $pin->getExpiryTime();
        if ($expiry !== null && $expiry < $now) {
            return;
        }

        $dailyQuantity = $this->extractorYield->programDailyAverage($quantityPerCycle, $cycleTime, $pin->getInstallTime(), $expiry);
        if ($dailyQuantity === null) {
            $flows['unknownExtraction'][$productTypeId] = true;

            return;
        }

        $this->addTo($flows['extraction'], [$productTypeId => $dailyQuantity]);
    }

    /**
     * @param ColonyFlows                   $flows
     * @param SchematicMap $schematicMap
     */
    private function addFactory(array &$flows, PlanetaryPin $pin, array $schematicMap): void
    {
        $flows['hasFactory'] = true;

        $schematicId = $pin->getSchematicId();
        $schematic = $schematicId === null ? null : $this->schematicRepository->findBySchematicId($schematicId);
        if ($schematicId === null || !isset($schematicMap[$schematicId]) || $schematic === null || $schematic->getCycleTime() <= 0) {
            $flows['complete'] = false;

            return;
        }

        $cyclesPerDay = self::SECONDS_PER_DAY / (float) $schematic->getCycleTime();
        $inputs = [];
        foreach ($schematicMap[$schematicId]['inputs'] as $inputTypeId => $inputQuantity) {
            $inputs[$inputTypeId] = $inputQuantity * $cyclesPerDay;
        }
        $flows['factoryInputsByPin'][$pin->getPinId()] = $inputs;
        $this->addTo($flows['required'], $inputs);

        $output = $schematicMap[$schematicId]['output'];
        if ($output !== null) {
            $this->addTo($flows['factoryOutput'], [$output['typeId'] => $output['quantity'] * $cyclesPerDay]);
        }
    }

    /**
     * @param ColonyFlows         $flows
     * @param array<int, InvType> $sdeTypes
     * @return array<string, mixed>
     */
    private function colonyRow(PlanetaryColony $colony, array $flows, array $sdeTypes, \DateTimeImmutable $now): array
    {
        $needs = [];
        $requiredTypeIds = array_keys($flows['required']);
        sort($requiredTypeIds);

        foreach ($requiredTypeIds as $typeId) {
            $required = $flows['required'][$typeId];
            $producedLocally = null;
            $netImport = null;
            if (!isset($flows['unknownExtraction'][$typeId])) {
                $producedLocally = $this->sumOf($flows['extraction'], $typeId) + $this->sumOf($flows['factoryOutput'], $typeId);
                $netImport = max(0.0, $required - $producedLocally);
            }

            $needs[] = [
                'typeId' => $typeId,
                'typeName' => $this->typeNameResolver->findName($typeId),
                'dailyRequired' => $required,
                'dailyProducedLocally' => $producedLocally,
                'dailyNetImport' => $netImport,
                'volumePerUnit' => $this->volumeOf($sdeTypes, $typeId),
            ];
        }

        return [
            'colonyId' => $colony->getId()?->toRfc4122(),
            'planetId' => $colony->getPlanetId(),
            'planetName' => $colony->getPlanetName(),
            'planetType' => $colony->getPlanetType(),
            'solarSystemName' => $colony->getSolarSystemName(),
            'characterId' => $colony->getCharacter()->getEveCharacterId(),
            'characterName' => $colony->getCharacter()->getName(),
            'complete' => $flows['complete'],
            'needs' => $needs,
        ] + $this->dataAgeFields($colony, $now);
    }

    /**
     * @param ColonyFlows         $flows
     * @param array<int, InvType> $sdeTypes
     * @return list<array<string, mixed>>
     */
    private function launchpadRows(PlanetaryColony $colony, array $flows, array $sdeTypes, \DateTimeImmutable $now): array
    {
        $launchpads = [];
        foreach ($colony->getPins() as $pin) {
            if ($this->isLaunchpad($pin, $sdeTypes)) {
                $launchpads[$pin->getPinId()] = $pin;
            }
        }
        if ($launchpads === []) {
            return [];
        }

        $ratesByPin = $this->cappedAtNetImport($this->feedRatesByLaunchpad($colony, $flows, array_keys($launchpads)), $flows);

        $rows = [];
        foreach ($launchpads as $pinId => $pin) {
            $rows[] = $this->launchpadRow($colony, $pin, $ratesByPin[$pinId], $sdeTypes, $now);
        }

        return $rows;
    }

    /**
     * @param ColonyFlows $flows
     * @param list<int>   $launchpadPinIds
     * @return array<int, array<int, float>> launchpad pinId => typeId => daily rate
     */
    private function feedRatesByLaunchpad(PlanetaryColony $colony, array $flows, array $launchpadPinIds): array
    {
        $rates = array_fill_keys($launchpadPinIds, []);

        $routesByFlow = [];
        foreach ($colony->getRoutes() as $route) {
            $inputs = $flows['factoryInputsByPin'][$route->getDestinationPinId()] ?? null;
            if ($inputs !== null && array_key_exists($route->getContentTypeId(), $inputs)) {
                $routesByFlow[$route->getDestinationPinId().':'.$route->getContentTypeId()][] = $route;
            }
        }

        foreach ($routesByFlow as $routes) {
            $dailyNeed = $flows['factoryInputsByPin'][$routes[0]->getDestinationPinId()][$routes[0]->getContentTypeId()];
            $totalWeight = array_sum(array_map(static fn (PlanetaryRoute $route): float => $route->getQuantity(), $routes));

            foreach ($routes as $route) {
                $share = $totalWeight > 0 ? $route->getQuantity() / $totalWeight : 1 / count($routes);
                if (array_key_exists($route->getSourcePinId(), $rates)) {
                    $this->addTo($rates[$route->getSourcePinId()], [$route->getContentTypeId() => $dailyNeed * $share]);
                }
            }
        }

        return $rates;
    }

    /**
     * @param array<int, array<int, float>> $ratesByPin launchpad pinId => typeId => daily rate
     * @param ColonyFlows                   $flows
     * @return array<int, array<int, float>>
     */
    private function cappedAtNetImport(array $ratesByPin, array $flows): array
    {
        $totalByType = [];
        foreach ($ratesByPin as $rates) {
            $this->addTo($totalByType, $rates);
        }

        $capped = [];
        foreach ($ratesByPin as $pinId => $rates) {
            $capped[$pinId] = [];
            foreach ($rates as $typeId => $rate) {
                if (isset($flows['unknownExtraction'][$typeId])) {
                    $capped[$pinId][$typeId] = $rate;
                    continue;
                }

                $netImport = max(0.0, $this->sumOf($flows['required'], $typeId) - $this->sumOf($flows['extraction'], $typeId) - $this->sumOf($flows['factoryOutput'], $typeId));
                $scaled = $totalByType[$typeId] > $netImport ? $rate * $netImport / $totalByType[$typeId] : $rate;
                if ($scaled > 0.0) {
                    $capped[$pinId][$typeId] = $scaled;
                }
            }
        }

        return $capped;
    }

    /**
     * @param array<int, float>   $feedRates
     * @param array<int, InvType> $sdeTypes
     * @return array<string, mixed>
     */
    private function launchpadRow(PlanetaryColony $colony, PlanetaryPin $pin, array $feedRates, array $sdeTypes, \DateTimeImmutable $now): array
    {
        $stock = [];
        foreach ($pin->getContents() ?? [] as $content) {
            if ($content['amount'] > 0) {
                $this->addTo($stock, [$content['type_id'] => (float) $content['amount']]);
            }
        }

        $ageSeconds = $this->dataAgeSeconds($colony, $now);
        $feedTypeIds = array_keys($feedRates);
        sort($feedTypeIds);
        $feeds = [];
        foreach ($feedTypeIds as $typeId) {
            $dailyRate = $feedRates[$typeId];
            $currentStock = $this->sumOf($stock, $typeId);
            $feeds[] = [
                'typeId' => $typeId,
                'typeName' => $this->typeNameResolver->findName($typeId),
                'dailyRate' => $dailyRate,
                'stock' => $currentStock,
                'projectedStock' => max(0.0, $currentStock - $dailyRate * $ageSeconds / self::SECONDS_PER_DAY),
                'emptyAt' => $dailyRate > 0
                    ? $colony->getLastUpdate()->modify(sprintf('+%d seconds', (int) round($currentStock / $dailyRate * self::SECONDS_PER_DAY)))->format('c')
                    : null,
                'volumePerUnit' => $this->volumeOf($sdeTypes, $typeId),
            ];
        }

        return [
            'colonyId' => $colony->getId()?->toRfc4122(),
            'characterId' => $colony->getCharacter()->getEveCharacterId(),
            'characterName' => $colony->getCharacter()->getName(),
            'planetId' => $colony->getPlanetId(),
            'planetName' => $colony->getPlanetName(),
            'pinId' => $pin->getPinId(),
            'typeId' => $pin->getTypeId(),
            'typeName' => $this->typeNameResolver->findName($pin->getTypeId()),
            'capacityM3' => $sdeTypes[$pin->getTypeId()]->getCapacity(),
            'usedVolumeM3' => $this->volumeOfQuantities($sdeTypes, $stock),
            'dailyVolumeM3' => $this->volumeOfQuantities($sdeTypes, $feedRates),
            'feeds' => $feeds,
        ] + $this->dataAgeFields($colony, $now);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractionRow(PlanetaryColony $colony, \DateTimeImmutable $now): ?array
    {
        $extractors = [];
        foreach ($colony->getPins() as $pin) {
            if ($pin->isExtractor()) {
                $extractors[$pin->getPinId()] = $this->extractorRow($colony, $pin, $now);
            }
        }
        if ($extractors === []) {
            return null;
        }
        ksort($extractors);

        return [
            'colonyId' => $colony->getId()?->toRfc4122(),
            'planetId' => $colony->getPlanetId(),
            'planetName' => $colony->getPlanetName(),
            'extractors' => array_values($extractors),
        ] + $this->dataAgeFields($colony, $now);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractorRow(PlanetaryColony $colony, PlanetaryPin $pin, \DateTimeImmutable $now): array
    {
        $productTypeId = $pin->getExtractorProductTypeId();
        $expiry = $pin->getExpiryTime();
        $cycleTime = $pin->getExtractorCycleTime();
        $quantityPerCycle = $pin->getExtractorQtyPerCycle();
        $extracted = null;
        if ($cycleTime !== null && $quantityPerCycle !== null) {
            $windowEnd = $expiry !== null && $expiry < $now ? $expiry : $now;
            $extracted = $this->extractorYield->yieldBetween($quantityPerCycle, $cycleTime, $pin->getInstallTime(), $expiry, $colony->getLastUpdate(), $windowEnd);
        }

        return [
            'pinId' => $pin->getPinId(),
            'typeId' => $productTypeId,
            'typeName' => $productTypeId === null ? null : $this->typeNameResolver->findName($productTypeId),
            'expired' => $expiry !== null && $expiry < $now,
            'programEndsAt' => $expiry?->format('c'),
            'extractedSinceLastView' => $extracted,
        ];
    }

    private function dataAgeSeconds(PlanetaryColony $colony, \DateTimeImmutable $now): int
    {
        return max(0, $now->getTimestamp() - $colony->getLastUpdate()->getTimestamp());
    }

    /**
     * @return array{lastViewAt: string, dataAgeSeconds: int, dataStale: bool}
     */
    private function dataAgeFields(PlanetaryColony $colony, \DateTimeImmutable $now): array
    {
        $ageSeconds = $this->dataAgeSeconds($colony, $now);

        return [
            'lastViewAt' => $colony->getLastUpdate()->format('c'),
            'dataAgeSeconds' => $ageSeconds,
            'dataStale' => $ageSeconds > self::STALE_DATA_AFTER_SECONDS,
        ];
    }

    /**
     * @param array<int, float> $consumption
     * @param array<int, float> $extraction
     * @param array<int, true>  $unknownExtraction
     * @param array<int, float> $factoryOutput
     * @return list<array<string, mixed>>
     */
    private function weeklyRows(array $consumption, array $extraction, array $unknownExtraction, array $factoryOutput): array
    {
        $typeIds = array_keys($consumption);
        sort($typeIds);

        $rows = [];
        foreach ($typeIds as $typeId) {
            $weeklyConsumption = $consumption[$typeId] * self::DAYS_PER_WEEK;
            $weeklyFactoryOutput = $this->sumOf($factoryOutput, $typeId) * self::DAYS_PER_WEEK;
            $weeklyExtraction = isset($unknownExtraction[$typeId])
                ? null
                : $this->sumOf($extraction, $typeId) * self::DAYS_PER_WEEK;

            $rows[] = [
                'typeId' => $typeId,
                'typeName' => $this->typeNameResolver->findName($typeId),
                'weeklyConsumption' => $weeklyConsumption,
                'weeklyExtraction' => $weeklyExtraction,
                'weeklyFactoryOutput' => $weeklyFactoryOutput,
                'weeklyNetNeed' => $weeklyExtraction === null
                    ? null
                    : max(0.0, $weeklyConsumption - $weeklyExtraction - $weeklyFactoryOutput),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, InvType> $sdeTypes
     */
    private function isLaunchpad(PlanetaryPin $pin, array $sdeTypes): bool
    {
        $type = $sdeTypes[$pin->getTypeId()] ?? null;

        return $type !== null && $type->getGroup()->getGroupId() === self::SPACEPORTS_GROUP_ID;
    }

    /**
     * @param array<int, InvType> $sdeTypes
     */
    private function volumeOf(array $sdeTypes, int $typeId): ?float
    {
        return isset($sdeTypes[$typeId]) ? $sdeTypes[$typeId]->getVolume() : null;
    }

    /**
     * @param array<int, InvType> $sdeTypes
     * @param array<int, float>   $quantities
     */
    private function volumeOfQuantities(array $sdeTypes, array $quantities): ?float
    {
        $total = 0.0;
        foreach ($quantities as $typeId => $quantity) {
            $volume = $this->volumeOf($sdeTypes, $typeId);
            if ($volume === null) {
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

    /**
     * @param array<int, float> $target
     * @param array<int, float> $addition
     */
    private function addTo(array &$target, array $addition): void
    {
        foreach ($addition as $typeId => $quantity) {
            $target[$typeId] = $this->sumOf($target, $typeId) + $quantity;
        }
    }

    /**
     * @param PlanetaryColony[]             $colonies
     * @param SchematicMap $schematicMap
     * @return array<int, InvType>
     */
    private function loadSdeTypes(array $colonies, array $schematicMap): array
    {
        $typeIds = [];
        foreach ($colonies as $colony) {
            foreach ($colony->getPins() as $pin) {
                $typeIds[$pin->getTypeId()] = true;
                foreach ($pin->getContents() ?? [] as $content) {
                    $typeIds[$content['type_id']] = true;
                }
            }
        }
        foreach ($schematicMap as $schematic) {
            foreach (array_keys($schematic['inputs']) as $inputTypeId) {
                $typeIds[$inputTypeId] = true;
            }
        }

        return $typeIds === [] ? [] : $this->invTypeRepository->findByTypeIds(array_keys($typeIds));
    }
}
