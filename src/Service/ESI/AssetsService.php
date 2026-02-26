<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Dto\AssetDto;
use App\Entity\CachedStructure;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AssetsService
{
    /** @var array<int, array{name: string, solar_system_id: ?int, owner_id?: ?int, type_id?: ?int}> */
    private array $structureCache = [];

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return AssetDto[]
     */
    public function getCharacterAssets(Character $character): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            return [];
        }

        $characterId = $character->getEveCharacterId();
        $rawAssets = $this->esiClient->getPaginated("/characters/{$characterId}/assets/", $token);

        return $this->processAssets($rawAssets, $token, $characterId, null);
    }

    /**
     * @return AssetDto[]
     */
    public function getCorporationAssets(Character $character): array
    {
        $token = $character->getEveToken();

        if ($token === null) {
            return [];
        }

        $corporationId = $character->getCorporationId();
        $rawAssets = $this->esiClient->getPaginated("/corporations/{$corporationId}/assets/", $token);

        return $this->processAssets($rawAssets, $token, null, $corporationId);
    }

    /**
     * @param array<array<string, mixed>> $rawAssets
     * @return AssetDto[]
     */
    private function processAssets(array $rawAssets, mixed $token, ?int $characterId, ?int $corporationId): array
    {
        if (empty($rawAssets)) {
            return [];
        }

        // Collect unique type IDs and location IDs
        $typeIds = array_unique(array_column($rawAssets, 'type_id'));
        $locationIds = array_unique(array_column($rawAssets, 'location_id'));
        $itemIds = array_column($rawAssets, 'item_id');

        // Get type names
        $typeNames = $this->resolveTypeNames($typeIds);

        // Get location info (names + solar systems)
        $locationInfo = $this->resolveLocationInfo($locationIds, $token);

        // Get item names (custom names for containers, ships, etc.)
        $itemNames = $this->resolveItemNames($itemIds, $token, $characterId, $corporationId);

        $assets = [];

        foreach ($rawAssets as $raw) {
            $typeId = $raw['type_id'];
            $locationId = $raw['location_id'];
            $itemId = $raw['item_id'];
            $info = $locationInfo[$locationId] ?? null;

            $assets[] = new AssetDto(
                itemId: $itemId,
                typeId: $typeId,
                typeName: $typeNames[$typeId] ?? "Type #{$typeId}",
                quantity: $raw['quantity'],
                locationId: $locationId,
                locationName: $info['name'] ?? "Location #{$locationId}",
                locationType: $raw['location_type'],
                locationFlag: $raw['location_flag'] ?? null,
                solarSystemId: $info['solar_system_id'] ?? null,
                solarSystemName: $info['solar_system_name'] ?? null,
                itemName: $itemNames[$itemId] ?? null,
            );
        }

        return $assets;
    }

    /**
     * @param int[] $itemIds
     * @return array<int, string>
     */
    private function resolveItemNames(array $itemIds, mixed $token, ?int $characterId, ?int $corporationId): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $names = [];
        $endpoint = $characterId !== null
            ? "/characters/{$characterId}/assets/names/"
            : "/corporations/{$corporationId}/assets/names/";

        // ESI accepts up to 1000 IDs per request
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            try {
                $data = $this->esiClient->post($endpoint, $chunk, $token);
                foreach ($data as $item) {
                    // Only store non-empty names (items with custom names)
                    if (!empty($item['name']) && $item['name'] !== 'None') {
                        $names[$item['item_id']] = $item['name'];
                    }
                }
            } catch (\Throwable) {
                // Ignore errors - item names are optional
            }
        }

        return $names;
    }

    /**
     * @param int[] $typeIds
     * @return array<int, string>
     */
    private function resolveTypeNames(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        $names = [];

        // ESI universe/names endpoint accepts up to 1000 IDs
        foreach (array_chunk($typeIds, 1000) as $chunk) {
            $data = $this->esiClient->post('/universe/names/', $chunk);

            foreach ($data as $item) {
                $names[$item['id']] = $item['name'];
            }
        }

        return $names;
    }

    /**
     * @param int[] $locationIds
     * @return array<int, array{name: string, solar_system_id: ?int, solar_system_name: ?string}>
     */
    private function resolveLocationInfo(array $locationIds, mixed $token): array
    {
        if (empty($locationIds)) {
            return [];
        }

        $info = [];
        $solarSystemIds = [];

        // Separate station IDs (< 1000000000000) from structure IDs
        $stationIds = [];
        $structureIds = [];

        foreach ($locationIds as $id) {
            if ($id < 1000000000000) {
                $stationIds[] = $id;
            } else {
                $structureIds[] = $id;
            }
        }

        // Pre-load cached structures from database
        if (!empty($structureIds)) {
            $cachedStructures = $this->cachedStructureRepository->findByStructureIds($structureIds);
            foreach ($cachedStructures as $structureId => $cached) {
                $this->structureCache[$structureId] = [
                    'name' => $cached->getName(),
                    'solar_system_id' => $cached->getSolarSystemId(),
                    'owner_id' => $cached->getOwnerCorporationId(),
                    'type_id' => $cached->getTypeId(),
                ];
            }
        }

        // Resolve station info
        foreach ($stationIds as $stationId) {
            try {
                $data = $this->esiClient->get("/universe/stations/{$stationId}/");
                $info[$stationId] = [
                    'name' => $data['name'],
                    'solar_system_id' => $data['system_id'] ?? null,
                    'solar_system_name' => null,
                ];
                if (isset($data['system_id'])) {
                    $solarSystemIds[$data['system_id']] = true;
                }
            } catch (\Throwable) {
                // Try universe/names as fallback
                try {
                    $data = $this->esiClient->post('/universe/names/', [$stationId]);
                    $info[$stationId] = [
                        'name' => $data[0]['name'] ?? "Station #{$stationId}",
                        'solar_system_id' => null,
                        'solar_system_name' => null,
                    ];
                } catch (\Throwable) {
                    $info[$stationId] = [
                        'name' => "Station #{$stationId}",
                        'solar_system_id' => null,
                        'solar_system_name' => null,
                    ];
                }
            }
        }

        // Resolve structure info (requires token)
        foreach ($structureIds as $structureId) {
            $resolved = $this->resolveStructure($structureId, $token);
            $info[$structureId] = $resolved;
            if ($resolved['solar_system_id'] !== null) {
                $solarSystemIds[$resolved['solar_system_id']] = true;
            }
        }

        // Resolve solar system names
        if (!empty($solarSystemIds)) {
            $systemNames = $this->resolveSolarSystemNames(array_keys($solarSystemIds));
            foreach ($info as $locationId => &$locationInfo) {
                if ($locationInfo['solar_system_id'] !== null) {
                    $locationInfo['solar_system_name'] = $systemNames[$locationInfo['solar_system_id']] ?? null;
                }
            }
        }

        return $info;
    }

    /**
     * @param int[] $systemIds
     * @return array<int, string>
     */
    private function resolveSolarSystemNames(array $systemIds): array
    {
        if (empty($systemIds)) {
            return [];
        }

        $names = [];

        // Use SDE data for solar system names
        foreach ($systemIds as $systemId) {
            $solarSystem = $this->solarSystemRepository->findBySolarSystemId($systemId);
            if ($solarSystem !== null) {
                $names[$systemId] = $solarSystem->getSolarSystemName();
            }
        }

        return $names;
    }

    /**
     * Try to resolve a structure using the given token, then fallback to other tokens.
     *
     * @return array{name: string, solar_system_id: ?int, solar_system_name: ?string, owner_id: ?int, type_id: ?int}
     */
    private function resolveStructure(int $structureId, mixed $primaryToken): array
    {
        // Check memory cache first
        if (isset($this->structureCache[$structureId])) {
            $cached = $this->structureCache[$structureId];
            return [
                'name' => $cached['name'],
                'solar_system_id' => $cached['solar_system_id'],
                'solar_system_name' => null,
                'owner_id' => $cached['owner_id'] ?? null,
                'type_id' => $cached['type_id'] ?? null,
            ];
        }

        // Check database cache
        $dbCached = $this->cachedStructureRepository->findByStructureId($structureId);
        if ($dbCached !== null) {
            $this->structureCache[$structureId] = [
                'name' => $dbCached->getName(),
                'solar_system_id' => $dbCached->getSolarSystemId(),
                'owner_id' => $dbCached->getOwnerCorporationId(),
                'type_id' => $dbCached->getTypeId(),
            ];
            return [
                'name' => $dbCached->getName(),
                'solar_system_id' => $dbCached->getSolarSystemId(),
                'solar_system_name' => null,
                'owner_id' => $dbCached->getOwnerCorporationId(),
                'type_id' => $dbCached->getTypeId(),
            ];
        }

        // Try with primary token
        $result = $this->tryResolveStructureWithToken($structureId, $primaryToken);
        if ($result !== null) {
            $this->cacheStructure($structureId, $result);
            return [...$result, 'solar_system_name' => null];
        }

        // Could not resolve - cache as "unresolved" to avoid retrying on every sync
        $this->logger->warning('Could not resolve structure', [
            'structureId' => $structureId,
        ]);

        $unresolvedData = [
            'name' => "Structure #{$structureId}",
            'solar_system_id' => null,
            'owner_id' => null,
            'type_id' => null,
        ];
        $this->cacheStructure($structureId, $unresolvedData);

        return [
            'name' => "Structure #{$structureId}",
            'solar_system_id' => null,
            'solar_system_name' => null,
            'owner_id' => null,
            'type_id' => null,
        ];
    }

    /**
     * Cache a resolved structure in memory and database.
     *
     * @param array{name: string, solar_system_id: ?int, owner_id: ?int, type_id: ?int} $data
     */
    private function cacheStructure(int $structureId, array $data): void
    {
        // Memory cache
        $this->structureCache[$structureId] = $data;

        // Check if already in database (might have been loaded in batch earlier)
        $existing = $this->cachedStructureRepository->findByStructureId($structureId);
        if ($existing !== null) {
            // Update if name, owner or type changed
            $needsUpdate = $existing->getName() !== $data['name']
                || $existing->getOwnerCorporationId() !== ($data['owner_id'] ?? null)
                || $existing->getTypeId() !== ($data['type_id'] ?? null);
            if ($needsUpdate) {
                $existing->setName($data['name']);
                $existing->setSolarSystemId($data['solar_system_id']);
                $existing->setOwnerCorporationId($data['owner_id'] ?? null);
                $existing->setTypeId($data['type_id'] ?? null);
                $existing->setResolvedAt(new \DateTimeImmutable());
            }
            return;
        }

        // Database cache (persist for future requests/users)
        $cached = new CachedStructure();
        $cached->setStructureId($structureId);
        $cached->setName($data['name']);
        $cached->setSolarSystemId($data['solar_system_id']);
        $cached->setOwnerCorporationId($data['owner_id'] ?? null);
        $cached->setTypeId($data['type_id'] ?? null);

        $this->entityManager->persist($cached);
        // Note: flush will be called at the end of the sync process
    }

    /**
     * @return array{name: string, solar_system_id: ?int, owner_id: ?int, type_id: ?int}|null
     */
    private function tryResolveStructureWithToken(int $structureId, mixed $token): ?array
    {
        if (!$token instanceof EveToken) {
            return null;
        }

        $characterName = $token->getCharacter()?->getName() ?? 'unknown';

        try {
            $data = $this->esiClient->get("/universe/structures/{$structureId}/", $token);
            $this->logger->info('Structure resolved', [
                'structureId' => $structureId,
                'name' => $data['name'],
                'character' => $characterName,
            ]);
            return [
                'name' => $data['name'],
                'solar_system_id' => $data['solar_system_id'] ?? null,
                'owner_id' => $data['owner_id'] ?? null,
                'type_id' => $data['type_id'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to resolve structure with token', [
                'structureId' => $structureId,
                'character' => $characterName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
