<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use App\ApiResource\Planetary\ColonyResource;
use App\Entity\PlanetaryColony;
use App\Entity\PlanetaryPin;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\PlanetSchematicRepository;

class PlanetaryResourceMapper
{
    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
        private readonly PlanetSchematicRepository $schematicRepository,
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

    private function mapPin(PlanetaryPin $pin): array
    {
        $schematicDetail = $pin->getSchematicId()
            ? $this->resolveSchematicDetail($pin->getSchematicId())
            : null;

        return [
            'pinId' => $pin->getPinId(),
            'typeId' => $pin->getTypeId(),
            'typeName' => $pin->getTypeName() ?? $this->resolveTypeName($pin->getTypeId()),
            'pinCategory' => $this->derivePinCategory($pin),
            'schematicId' => $pin->getSchematicId(),
            'schematicName' => $schematicDetail['name'] ?? null,
            'schematicCycleTime' => $schematicDetail['cycleTime'] ?? null,
            'schematicInputs' => $schematicDetail['inputs'] ?? [],
            'schematicOutput' => $schematicDetail['output'] ?? null,
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

    private function mapContents(?array $contents): array
    {
        if (empty($contents)) {
            return [];
        }

        return array_map(fn (array $item) => [
            'typeId' => $item['type_id'],
            'typeName' => $this->resolveTypeName($item['type_id']),
            'amount' => $item['amount'],
        ], $contents);
    }

    private function resolveTypeName(int $typeId): string
    {
        $type = $this->invTypeRepository->find($typeId);

        return $type?->getTypeName() ?? "Type #{$typeId}";
    }

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
}
