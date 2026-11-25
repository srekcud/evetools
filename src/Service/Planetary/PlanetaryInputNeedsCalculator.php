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

    public function __construct(
        private readonly PlanetSchematicRepository $schematicRepository,
        private readonly PlanetSchematicTypeRepository $schematicTypeRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return array{colonies: list<array<string, mixed>>, launchpads: list<array<string, mixed>>, weekly: list<array<string, mixed>>, complete: bool}
     */
    public function calculate(array $colonies): array
    {
        $schematicMap = $this->schematicTypeRepository->getSchematicMap();
        $sdeTypes = $this->loadSdeTypes($colonies, $schematicMap);

        $colonyRows = [];
        $launchpadRows = [];
        $consumption = [];
        $extraction = [];
        $unknownExtraction = [];
        $factoryOutput = [];
        $complete = true;

        foreach ($colonies as $colony) {
            $flows = $this->analyseColony($colony, $schematicMap);

            if ($flows['hasFactory']) {
                $colonyRows[] = $this->colonyRow($colony, $flows, $sdeTypes);
            }
            foreach ($this->launchpadRows($colony, $flows, $sdeTypes) as $row) {
                $launchpadRows[] = $row;
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
            'weekly' => $this->weeklyRows($consumption, $extraction, $unknownExtraction, $factoryOutput),
            'complete' => $complete,
        ];
    }

    /**
     * @param SchematicMap $schematicMap
     * @return ColonyFlows
     */
    private function analyseColony(PlanetaryColony $colony, array $schematicMap): array
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
        $now = $this->clock->now();

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

        $this->addTo($flows['extraction'], [$productTypeId => $quantityPerCycle * (self::SECONDS_PER_DAY / (float) $cycleTime)]);
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
    private function colonyRow(PlanetaryColony $colony, array $flows, array $sdeTypes): array
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
        ];
    }

    /**
     * @param ColonyFlows         $flows
     * @param array<int, InvType> $sdeTypes
     * @return list<array<string, mixed>>
     */
    private function launchpadRows(PlanetaryColony $colony, array $flows, array $sdeTypes): array
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

        $ratesByPin = $this->feedRatesByLaunchpad($colony, $flows, array_keys($launchpads));

        $rows = [];
        foreach ($launchpads as $pinId => $pin) {
            $rows[] = $this->launchpadRow($colony, $pin, $ratesByPin[$pinId], $sdeTypes);
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
     * @param array<int, float>   $feedRates
     * @param array<int, InvType> $sdeTypes
     * @return array<string, mixed>
     */
    private function launchpadRow(PlanetaryColony $colony, PlanetaryPin $pin, array $feedRates, array $sdeTypes): array
    {
        $stock = [];
        foreach ($pin->getContents() ?? [] as $content) {
            if ($content['amount'] > 0) {
                $this->addTo($stock, [$content['type_id'] => (float) $content['amount']]);
            }
        }

        $feedTypeIds = array_keys($feedRates);
        sort($feedTypeIds);
        $feeds = [];
        foreach ($feedTypeIds as $typeId) {
            $feeds[] = [
                'typeId' => $typeId,
                'typeName' => $this->typeNameResolver->findName($typeId),
                'dailyRate' => $feedRates[$typeId],
                'stock' => $this->sumOf($stock, $typeId),
                'volumePerUnit' => $this->volumeOf($sdeTypes, $typeId),
            ];
        }

        return [
            'colonyId' => $colony->getId()?->toRfc4122(),
            'planetId' => $colony->getPlanetId(),
            'planetName' => $colony->getPlanetName(),
            'pinId' => $pin->getPinId(),
            'typeId' => $pin->getTypeId(),
            'typeName' => $this->typeNameResolver->findName($pin->getTypeId()),
            'capacityM3' => $sdeTypes[$pin->getTypeId()]->getCapacity(),
            'usedVolumeM3' => $this->volumeOfQuantities($sdeTypes, $stock),
            'dailyVolumeM3' => $this->volumeOfQuantities($sdeTypes, $feedRates),
            'feeds' => $feeds,
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
