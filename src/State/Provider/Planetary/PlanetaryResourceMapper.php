<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use App\ApiResource\Planetary\ColonyResource;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\PlanetSchematicRepository;

class PlanetaryResourceMapper
{
    private const MARKET_GROUP_P1 = 1334;
    private const MARKET_GROUP_P2 = 1335;
    private const MARKET_GROUP_P3 = 1336;
    private const MARKET_GROUP_P4 = 1337;

    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
        private readonly PlanetSchematicRepository $schematicRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    /**
     * Map a colony entity to a ColonyResource DTO (collection mode, no pins/routes).
     */
    public function toResource(PlanetaryColony $colony): ColonyResource
    {
        $resource = new ColonyResource();
        $resource->id = $colony->getId()?->toRfc4122() ?? '';
        $resource->characterId = $colony->getCharacter()->getEveCharacterId();
        $resource->characterName = $colony->getCharacter()->getName();
        $resource->planetId = $colony->getPlanetId();
        $resource->planetName = $colony->getPlanetName();
        $resource->planetType = $colony->getPlanetType();
        $resource->solarSystemId = $colony->getSolarSystemId();
        $resource->solarSystemName = $colony->getSolarSystemName();
        $resource->solarSystemSecurity = $this->resolveSolarSystemSecurity($colony->getSolarSystemId());
        $resource->upgradeLevel = $colony->getUpgradeLevel();
        $resource->numPins = $colony->getNumPins();
        $resource->lastUpdate = $colony->getLastUpdate()->format('c');
        $resource->cachedAt = $colony->getCachedAt()->format('c');

        $this->computePinStats($colony, $resource);

        return $resource;
    }

    /**
     * Map a colony entity to a ColonyResource DTO (detail mode, with pins and routes).
     */
    public function toDetailResource(PlanetaryColony $colony): ColonyResource
    {
        $resource = $this->toResource($colony);

        foreach ($colony->getPins() as $pin) {
            $resource->pins[] = $this->mapPin($pin);
        }

        foreach ($colony->getRoutes() as $route) {
            $resource->routes[] = [
                'routeId' => $route->getRouteId(),
                'sourcePinId' => $route->getSourcePinId(),
                'destinationPinId' => $route->getDestinationPinId(),
                'contentTypeId' => $route->getContentTypeId(),
                'contentTypeName' => $this->resolveTypeName($route->getContentTypeId()),
                'quantity' => $route->getQuantity(),
                'waypoints' => $route->getWaypoints() ?? [],
            ];
        }

        return $resource;
    }

    private function computePinStats(PlanetaryColony $colony, ColonyResource $resource): void
    {
        $now = new \DateTimeImmutable();
        $nearestExpiry = null;

        foreach ($colony->getPins() as $pin) {
            if ($pin->isExtractor()) {
                $resource->extractorCount++;
                if ($pin->getExpiryTime() !== null && $pin->getExpiryTime() > $now) {
                    $resource->activeExtractors++;
                }
                $expiry = $pin->getExpiryTime();
                if ($expiry !== null && ($nearestExpiry === null || $expiry < $nearestExpiry)) {
                    $nearestExpiry = $expiry;
                }
            } elseif ($pin->isFactory()) {
                $resource->factoryCount++;
            }
        }

        $resource->nearestExpiry = $nearestExpiry?->format('c');
        $resource->status = $this->computeStatus($colony);
    }

    private function computeStatus(PlanetaryColony $colony): string
    {
        $now = new \DateTimeImmutable();
        $hasActive = false;
        $hasExpiring = false;
        $hasExpired = false;

        foreach ($colony->getPins() as $pin) {
            if (!$pin->isExtractor()) {
                continue;
            }
            $expiry = $pin->getExpiryTime();
            if ($expiry === null) {
                continue;
            }

            if ($expiry < $now) {
                $hasExpired = true;
            } elseif ($pin->isExpiringSoon(24)) {
                $hasExpiring = true;
            } else {
                $hasActive = true;
            }
        }

        if ($hasExpired) {
            return 'expired';
        }
        if ($hasExpiring) {
            return 'expiring';
        }
        if ($hasActive) {
            return 'active';
        }

        return 'idle';
    }

    /** @return array<string, mixed> */
    private function mapPin(PlanetaryPin $pin): array
    {
        $schematicDetail = $pin->getSchematicId()
            ? $this->resolveSchematicDetail($pin->getSchematicId())
            : null;

        // Resolve storage capacity from SDE (InvType.capacity) for the pin type
        $pinType = $this->invTypeRepository->find($pin->getTypeId());
        $capacity = $pinType?->getCapacity();

        return [
            'pinId' => $pin->getPinId(),
            'typeId' => $pin->getTypeId(),
            'typeName' => $pin->getTypeName() ?? $this->resolveTypeName($pin->getTypeId()),
            'pinCategory' => $this->derivePinCategory($pin),
            'capacity' => $capacity,
            'schematicId' => $pin->getSchematicId(),
            'schematicName' => $schematicDetail['name'] ?? null,
            'schematicCycleTime' => $schematicDetail['cycleTime'] ?? null,
            'schematicInputs' => $schematicDetail['inputs'] ?? [],
            'schematicOutput' => $schematicDetail['output'] ?? null,
            'outputTier' => $this->resolveOutputTier($pin, $schematicDetail),
            'latitude' => $pin->getLatitude(),
            'longitude' => $pin->getLongitude(),
            'installTime' => $pin->getInstallTime()?->format('c'),
            'expiryTime' => $pin->getExpiryTime()?->format('c'),
            'lastCycleStart' => $pin->getLastCycleStart()?->format('c'),
            'isExtractor' => $pin->isExtractor(),
            'isFactory' => $pin->isFactory(),
            'isExpired' => $pin->isExpired(),
            'isExpiringSoon' => $pin->isExpiringSoon(24),
            'extractorProductTypeId' => $pin->getExtractorProductTypeId(),
            'extractorProductName' => $pin->getExtractorProductTypeId()
                ? $this->resolveTypeName($pin->getExtractorProductTypeId())
                : null,
            'extractorCycleTime' => $pin->getExtractorCycleTime(),
            'extractorQtyPerCycle' => $pin->getExtractorQtyPerCycle(),
            'extractorHeadRadius' => $pin->getExtractorHeadRadius(),
            'extractorNumHeads' => $pin->getExtractorNumHeads(),
            'contents' => $this->mapContents($pin->getContents()),
        ];
    }

    private function derivePinCategory(PlanetaryPin $pin): string
    {
        if ($pin->isExtractor()) {
            return 'extractor';
        }
        if ($pin->isFactory()) {
            return 'factory';
        }

        $typeName = strtolower($pin->getTypeName() ?? '');
        if (str_contains($typeName, 'industry facility')) {
            return 'factory';
        }
        if (str_contains($typeName, 'launchpad')) {
            return 'launchpad';
        }
        if (str_contains($typeName, 'storage')) {
            return 'storage';
        }
        if (str_contains($typeName, 'command center')) {
            return 'command_center';
        }

        return 'other';
    }

    /**
     * @param array<int, array{type_id: int, amount: int}>|null $contents
     * @return list<array<string, mixed>>
     */
    private function mapContents(?array $contents): array
    {
        if (empty($contents)) {
            return [];
        }

        return array_values(array_map(function (array $item) {
            $type = $this->invTypeRepository->find($item['type_id']);

            return [
                'typeId' => $item['type_id'],
                'typeName' => $type?->getTypeName() ?? "Type #{$item['type_id']}",
                'amount' => $item['amount'],
                'volume' => $type?->getVolume(),
            ];
        }, $contents));
    }

    private function resolveTypeName(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);

        return $type?->getTypeName() ?? "Type #{$typeId}";
    }

    private function resolveSolarSystemSecurity(int $solarSystemId): ?float
    {
        $system = $this->solarSystemRepository->findBySolarSystemId($solarSystemId);

        return $system?->getSecurity();
    }

    /** @return array{name: string, cycleTime: int, inputs: list<array<string, mixed>>, output: array<string, mixed>|null} */
    private function resolveSchematicDetail(int $schematicId): array
    {
        $schematic = $this->schematicRepository->find($schematicId);

        if ($schematic === null) {
            return [
                'name' => "Schematic #{$schematicId}",
                'cycleTime' => 0,
                'inputs' => [],
                'output' => null,
            ];
        }

        $inputs = [];
        $output = null;

        foreach ($schematic->getSchematicTypes() as $schematicType) {
            $entry = [
                'typeId' => $schematicType->getTypeId(),
                'typeName' => $this->resolveTypeName($schematicType->getTypeId()),
                'quantity' => $schematicType->getQuantity(),
            ];

            if ($schematicType->isInput()) {
                $inputs[] = $entry;
            } else {
                $output = $entry;
            }
        }

        return [
            'name' => $schematic->getSchematicName(),
            'cycleTime' => $schematic->getCycleTime(),
            'inputs' => $inputs,
            'output' => $output,
        ];
    }

    /** @param array{name: string, cycleTime: int, inputs: list<array<string, mixed>>, output: array<string, mixed>|null}|null $schematicDetail */
    private function resolveOutputTier(PlanetaryPin $pin, ?array $schematicDetail): ?string
    {
        if ($pin->isExtractor() && $pin->getExtractorProductTypeId() !== null) {
            return $this->classifyTier($pin->getExtractorProductTypeId());
        }

        if ($pin->isFactory() && isset($schematicDetail['output']['typeId'])) {
            return $this->classifyTier($schematicDetail['output']['typeId']);
        }

        return null;
    }

    private function classifyTier(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);
        if ($type === null) {
            return 'P0';
        }

        $marketGroup = $type->getMarketGroup();
        if ($marketGroup === null) {
            return 'P0';
        }

        $current = $marketGroup;
        $depth = 0;
        while ($current !== null && $depth < 10) {
            $mgId = $current->getMarketGroupId();

            if ($mgId === self::MARKET_GROUP_P1) {
                return 'P1';
            }
            if ($mgId === self::MARKET_GROUP_P2) {
                return 'P2';
            }
            if ($mgId === self::MARKET_GROUP_P3) {
                return 'P3';
            }
            if ($mgId === self::MARKET_GROUP_P4) {
                return 'P4';
            }

            $current = $current->getParentGroup();
            $depth++;
        }

        return 'P0';
    }
}
