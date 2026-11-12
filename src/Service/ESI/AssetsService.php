<?php

declare(strict_types=1);

namespace App\Service\ESI;

use App\Constant\EveConstants;
use App\Dto\AssetDto;
use App\Entity\CachedStructure;
use App\Entity\Character;
use App\Entity\EveToken;
use App\Exception\EsiApiException;
use App\Repository\CachedStructureRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Repository\Sde\StaStationRepository;
use App\Service\TypeNameResolver;
use App\Service\UnknownTypeName;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AssetsService
{
    private const int FIRST_STRUCTURE_ID = 1000000000000;
    private const int NAMES_CHUNK_SIZE = 1000;
    private const string NO_CUSTOM_NAME = 'None';

    /** @var array<int, array{name: string, solar_system_id: ?int, owner_id?: ?int, type_id?: ?int}> */
    private array $structureCache = [];

    private int $nameBatchFailures = 0;

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly StaStationRepository $staStationRepository,
    ) {
    }

    public function lastNameBatchFailures(): int
    {
        return $this->nameBatchFailures;
    }

    public function resolveSingleItemName(int $itemId, EveToken $token, ?int $characterId, ?int $corporationId): ?string
    {
        $endpoint = $this->itemNamesEndpoint($characterId, $corporationId);
        $entries = $this->esiClient->post($endpoint, [$itemId], $token);

        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
                throw new EsiApiException('ESI returned an asset name entry without a text name', 502, $endpoint);
            }

            return $this->isCustomName($entry['name']) ? $entry['name'] : null;
        }

        return null;
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
        $this->nameBatchFailures = 0;

        if (empty($rawAssets)) {
            return [];
        }

        $singletonFlags = $this->singletonFlagsByItemId($rawAssets, $characterId, $corporationId);

        // Collect unique type IDs and location IDs with their types
        $typeIds = array_unique(array_column($rawAssets, 'type_id'));
        $nameableItemIds = array_column(
            array_filter(
                $rawAssets,
                static fn (array $raw): bool => $singletonFlags[$raw['item_id']]
                    && ($raw['location_flag'] ?? null) !== EveConstants::OFFICE_LOCATION_FLAG,
            ),
            'item_id',
        );
        $rawAssetsByItemId = array_column($rawAssets, null, 'item_id');

        // Collect resolvable location IDs (skip location_type "item" — those are container/ship item_ids, not structures)
        $locationIds = [];
        $solarSystemLocationIds = [];
        $ownedStructureIds = [];
        foreach ($rawAssets as $raw) {
            $locId = $raw['location_id'];
            $locationType = $raw['location_type'] ?? '';
            if ($locationType === 'solar_system') {
                $solarSystemLocationIds[$locId] = true;
            }
            if ($locationType !== 'item' || $this->isStructureOutsideAssetList($locId, $rawAssetsByItemId)) {
                $locationIds[$locId] = true;
            }
            if ($locationType === 'item' && $this->isSolarSystemRoot($rawAssetsByItemId[$locId] ?? null)) {
                $ownedStructureIds[$locId] = true;
            }
        }

        // Get type names
        $typeNames = $this->resolveTypeNames($typeIds);

        // Get item names (custom names for containers, ships, etc.)
        $itemNames = $this->resolveItemNames($nameableItemIds, $token, $characterId, $corporationId);

        foreach (array_keys($ownedStructureIds) as $structureId) {
            if (!isset($itemNames[$structureId])) {
                $locationIds[$structureId] = true;
            }
        }
        $locationIds = array_keys($locationIds);

        // Get location info (names + solar systems)
        $locationInfo = $this->resolveLocationInfo($locationIds, $token, array_keys($solarSystemLocationIds));

        $assets = [];

        foreach ($rawAssets as $raw) {
            $typeId = $raw['type_id'];
            $locationId = $raw['location_id'];
            $itemId = $raw['item_id'];
            $info = ($raw['location_type'] ?? '') === 'item'
                ? $this->resolveContainerLocation($locationId, $rawAssetsByItemId, $locationInfo, $typeNames, $itemNames)
                : $locationInfo[$locationId] ?? null;

            $assets[] = new AssetDto(
                itemId: $itemId,
                typeId: $typeId,
                typeName: $typeNames[$typeId] ?? null,
                quantity: $raw['quantity'],
                locationId: $locationId,
                locationName: $info['name'] ?? "Location #{$locationId}",
                locationType: $raw['location_type'],
                locationFlag: $raw['location_flag'] ?? null,
                solarSystemId: $info['solar_system_id'] ?? null,
                solarSystemName: $info['solar_system_name'] ?? null,
                itemName: $itemNames[$itemId] ?? null,
                isSingleton: $singletonFlags[$itemId],
            );
        }

        return $assets;
    }

    /**
     * Locates an item stored inside a container or ship by walking the parent chain
     * up to the root station/structure, labelled "<container> — <root location>".
     * Returns null when the chain is broken or cyclic, so the caller keeps its generic fallback.
     *
     * @param array<int, array<string, mixed>> $rawAssetsByItemId
     * @param array<int, array{name: string, solar_system_id: ?int, solar_system_name: ?string}> $locationInfo
     * @param array<int, string> $typeNames
     * @param array<int, string> $itemNames
     * @return array{name: string, solar_system_id: ?int, solar_system_name: ?string}|null
     */
    private function resolveContainerLocation(
        int $containerId,
        array $rawAssetsByItemId,
        array $locationInfo,
        array $typeNames,
        array $itemNames,
    ): ?array {
        $container = $rawAssetsByItemId[$containerId] ?? null;
        if ($container === null) {
            return $locationInfo[$containerId] ?? null;
        }

        if ($this->isSolarSystemRoot($container)) {
            return $this->resolveOwnedStructure($container, $locationInfo, $itemNames);
        }

        $current = $container;
        $visited = [$containerId => true];
        while (($current['location_type'] ?? '') === 'item' && isset($rawAssetsByItemId[$current['location_id']])) {
            $parentId = $current['location_id'];
            if (isset($visited[$parentId])) {
                return null;
            }
            $visited[$parentId] = true;
            $current = $rawAssetsByItemId[$parentId];
        }

        $root = $this->isSolarSystemRoot($current)
            ? $this->resolveOwnedStructure($current, $locationInfo, $itemNames)
            : $locationInfo[$current['location_id']] ?? null;
        if ($root === null) {
            return null;
        }

        $containerTypeId = $container['type_id'];
        $containerName = $itemNames[$containerId] ?? $typeNames[$containerTypeId] ?? UnknownTypeName::label($containerTypeId);

        return [...$root, 'name' => "{$containerName} — {$root['name']}"];
    }

    /**
     * @param array<string, mixed>|null $asset
     */
    private function isSolarSystemRoot(?array $asset): bool
    {
        return $asset !== null && ($asset['location_type'] ?? '') === 'solar_system';
    }

    /**
     * @param array<string, mixed> $structure
     * @param array<int, array{name: string, solar_system_id: ?int, solar_system_name: ?string}> $locationInfo
     * @param array<int, string> $itemNames
     * @return array{name: string, solar_system_id: ?int, solar_system_name: ?string}|null
     */
    private function resolveOwnedStructure(array $structure, array $locationInfo, array $itemNames): ?array
    {
        $structureId = $structure['item_id'];
        $structureName = $itemNames[$structureId] ?? ($locationInfo[$structureId]['name'] ?? null);
        if ($structureName === null) {
            return null;
        }

        $systemId = $structure['location_id'];

        return [
            'name' => $structureName,
            'solar_system_id' => $systemId,
            'solar_system_name' => $locationInfo[$systemId]['solar_system_name'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rawAssetsByItemId
     */
    private function isStructureOutsideAssetList(int $locationId, array $rawAssetsByItemId): bool
    {
        return $locationId >= self::FIRST_STRUCTURE_ID && !isset($rawAssetsByItemId[$locationId]);
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
        $endpoint = $this->itemNamesEndpoint($characterId, $corporationId);

        // ESI accepts up to 1000 IDs per request
        foreach (array_chunk($itemIds, self::NAMES_CHUNK_SIZE) as $chunk) {
            $fetched = $this->fetchItemNames($endpoint, $chunk, $token);
            $names += $fetched['names'];
            if ($fetched['rejected']) {
                ++$this->nameBatchFailures;
            }
        }

        return $names;
    }

    /**
     * @param array<array<string, mixed>> $rawAssets
     * @return array<int, bool>
     */
    private function singletonFlagsByItemId(array $rawAssets, ?int $characterId, ?int $corporationId): array
    {
        $flags = [];
        foreach ($rawAssets as $raw) {
            if (!array_key_exists('is_singleton', $raw) || !is_bool($raw['is_singleton'])) {
                throw new EsiApiException(
                    "ESI asset {$raw['item_id']} has a missing or non-boolean is_singleton field",
                    502,
                    $this->assetsEndpoint($characterId, $corporationId),
                );
            }
            $flags[$raw['item_id']] = $raw['is_singleton'];
        }

        return $flags;
    }

    private function assetsEndpoint(?int $characterId, ?int $corporationId): string
    {
        return $characterId !== null
            ? "/characters/{$characterId}/assets/"
            : "/corporations/{$corporationId}/assets/";
    }

    private function itemNamesEndpoint(?int $characterId, ?int $corporationId): string
    {
        return $characterId !== null
            ? "/characters/{$characterId}/assets/names/"
            : "/corporations/{$corporationId}/assets/names/";
    }

    private function isCustomName(string $name): bool
    {
        return $name !== '' && $name !== self::NO_CUSTOM_NAME;
    }

    /**
     * @param non-empty-array<int> $itemIds
     * @return array{names: array<int, string>, rejected: bool}
     */
    private function fetchItemNames(string $endpoint, array $itemIds, mixed $token): array
    {
        try {
            $data = $this->esiClient->post($endpoint, $itemIds, $token);
        } catch (\Throwable $e) {
            $this->logger->warning('Item names batch rejected by ESI', [
                'endpoint' => $endpoint,
                'batchSize' => count($itemIds),
                'error' => $e->getMessage(),
            ]);

            $names = [];
            if (count($itemIds) > 1) {
                foreach (array_chunk($itemIds, max(1, intdiv(count($itemIds) + 1, 2))) as $half) {
                    $names += $this->fetchItemNames($endpoint, $half, $token)['names'];
                }
            }

            return ['names' => $names, 'rejected' => true];
        }

        $names = [];
        foreach ($data as $item) {
            // Only store non-empty names (items with custom names)
            if (!empty($item['name']) && $item['name'] !== self::NO_CUSTOM_NAME) {
                $names[$item['item_id']] = $item['name'];
            }
        }

        return ['names' => $names, 'rejected' => false];
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

        $missingTypeIds = array_values(array_diff($typeIds, array_keys($names)));
        if ($missingTypeIds !== []) {
            $names += $this->typeNameResolver->findNames($missingTypeIds);
        }

        return $names;
    }

    /**
     * @param int[] $locationIds
     * @param int[] $solarSystemLocationIds
     * @return array<int, array{name: string, solar_system_id: ?int, solar_system_name: ?string}>
     */
    private function resolveLocationInfo(array $locationIds, mixed $token, array $solarSystemLocationIds = []): array
    {
        if (empty($locationIds)) {
            return [];
        }

        $info = [];
        $solarSystemIds = [];

        // Separate station IDs (< 1000000000000) from structure IDs
        $stationIds = [];
        $structureIds = [];

        $solarSystemLocations = array_flip($solarSystemLocationIds);

        foreach ($locationIds as $id) {
            if (isset($solarSystemLocations[$id])) {
                $solarSystemIds[$id] = true;
            } elseif ($id < self::FIRST_STRUCTURE_ID) {
                $stationIds[] = $id;
            } else {
                $structureIds[] = $id;
            }
        }

        // Pre-load cached structures from database (only resolved ones)
        if (!empty($structureIds)) {
            $cachedStructures = $this->cachedStructureRepository->findByStructureIds($structureIds);
            foreach ($cachedStructures as $structureId => $cached) {
                if (!str_starts_with($cached->getName(), 'Structure #')) {
                    $this->structureCache[$structureId] = [
                        'name' => $cached->getName(),
                        'solar_system_id' => $cached->getSolarSystemId(),
                        'owner_id' => $cached->getOwnerCorporationId(),
                        'type_id' => $cached->getTypeId(),
                    ];
                }
            }
        }

        // Resolve station info
        $sdeStations = $this->staStationRepository->findByStationIds($stationIds);
        foreach ($sdeStations as $stationId => $station) {
            $systemId = $station->getSolarSystem()->getSolarSystemId();
            $info[$stationId] = [
                'name' => $station->getStationName(),
                'solar_system_id' => $systemId,
                'solar_system_name' => null,
            ];
            $solarSystemIds[$systemId] = true;
        }

        foreach ($stationIds as $stationId) {
            if (isset($sdeStations[$stationId])) {
                continue;
            }

            $this->logger->warning('NPC station missing from the SDE, resolved through ESI', [
                'station_id' => $stationId,
            ]);

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
            foreach (array_keys($solarSystemLocations) as $systemId) {
                $info[$systemId] = [
                    'name' => $systemNames[$systemId] ?? "Solar system #{$systemId}",
                    'solar_system_id' => $systemId,
                    'solar_system_name' => null,
                ];
            }
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
        // Check memory cache first — skip unresolved entries so we retry resolution
        if (isset($this->structureCache[$structureId])) {
            $cached = $this->structureCache[$structureId];
            if (!str_starts_with($cached['name'], 'Structure #')) {
                return [
                    'name' => $cached['name'],
                    'solar_system_id' => $cached['solar_system_id'],
                    'solar_system_name' => null,
                    'owner_id' => $cached['owner_id'] ?? null,
                    'type_id' => $cached['type_id'] ?? null,
                ];
            }
        }

        // Check database cache — skip unresolved entries so we retry resolution
        $dbCached = $this->cachedStructureRepository->findByStructureId($structureId);
        if ($dbCached !== null && !str_starts_with($dbCached->getName(), 'Structure #')) {
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

        // Could not resolve — only create a new "unresolved" cache entry if none exists yet
        $this->logger->warning('Could not resolve structure', [
            'structureId' => $structureId,
        ]);

        $unresolvedData = [
            'name' => "Structure #{$structureId}",
            'solar_system_id' => null,
            'owner_id' => null,
            'type_id' => null,
        ];

        if ($dbCached === null) {
            $this->cacheStructure($structureId, $unresolvedData);
        }

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
