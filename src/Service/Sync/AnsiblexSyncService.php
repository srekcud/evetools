<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\AnsiblexJumpGate;
use App\Entity\Character;
use App\Repository\AnsiblexJumpGateRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AnsiblexSyncService
{
    private const ANSIBLEX_TYPE_ID = 35841;
    private const SYNC_INTERVAL_HOURS = 12;

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly AnsiblexJumpGateRepository $ansiblexRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sync Ansiblex gates from corporation structures.
     *
     * @return array{added: int, updated: int, deactivated: int}
     */
    public function syncFromCharacter(Character $character): array
    {
        $token = $character->getEveToken();
        if (!$token) {
            throw new \RuntimeException('Character has no EVE token');
        }

        $corporationId = $character->getCorporationId();
        $allianceId = $character->getAllianceId();

        $this->logger->info('Starting Ansiblex sync', [
            'character' => $character->getName(),
            'corporation_id' => $corporationId,
            'alliance_id' => $allianceId,
        ]);

        // Get corporation structures
        $structures = $this->getCorporationStructures($corporationId, $token);

        // Filter Ansiblex Jump Bridges
        $ansiblexStructures = array_filter($structures, fn($s) => $s['type_id'] === self::ANSIBLEX_TYPE_ID);

        $this->logger->info('Found Ansiblex structures', [
            'total_structures' => count($structures),
            'ansiblex_count' => count($ansiblexStructures),
        ]);

        $stats = ['added' => 0, 'updated' => 0, 'deactivated' => 0];
        $seenIds = [];

        foreach ($ansiblexStructures as $structure) {
            $result = $this->processAnsiblexStructure($structure, $token, $allianceId);
            if ($result === 'added') {
                $stats['added']++;
            } elseif ($result === 'updated') {
                $stats['updated']++;
            }
            $seenIds[] = $structure['structure_id'];
        }

        // Deactivate gates from this alliance that were not seen
        if ($allianceId) {
            $stats['deactivated'] = $this->deactivateNotSeenGates($allianceId, $seenIds);
        }

        $this->entityManager->flush();

        $this->logger->info('Ansiblex sync completed', $stats);

        return $stats;
    }

    private function getCorporationStructures(int $corporationId, $token): array
    {
        try {
            return $this->esiClient->getPaginated(
                "/corporations/{$corporationId}/structures/",
                $token
            );
        } catch (\App\Exception\EsiApiException $e) {
            $this->logger->warning('Failed to get corporation structures', [
                'corporation_id' => $corporationId,
                'status_code' => $e->statusCode,
                'error' => $e->getMessage(),
                'endpoint' => $e->endpoint,
            ]);

            // 403 usually means the character doesn't have Director/Station Manager role
            if ($e->statusCode === 403) {
                $this->logger->error('ESI 403 Forbidden: Character likely lacks required in-game roles (Director or Station_Manager)');
            }

            return [];
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get corporation structures', [
                'corporation_id' => $corporationId,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);
            return [];
        }
    }

    private function processAnsiblexStructure(array $structure, $token, ?int $allianceId): string
    {
        $structureId = $structure['structure_id'];
        $name = $structure['name'] ?? '';

        // Parse the Ansiblex name to extract source and destination
        // Format: "SystemA » SystemB" or "SystemA >> SystemB"
        $systems = $this->parseAnsiblexName($name);
        if (!$systems) {
            $this->logger->warning('Could not parse Ansiblex name', [
                'structure_id' => $structureId,
                'name' => $name,
            ]);
            return 'skipped';
        }

        [$sourceSystemName, $destSystemName] = $systems;

        // Resolve system IDs from names
        $sourceSystem = $this->findSolarSystemByName($sourceSystemName);
        $destSystem = $this->findSolarSystemByName($destSystemName);

        if (!$sourceSystem || !$destSystem) {
            // Try to get structure info from ESI to get the solar system
            $structureInfo = $this->getStructureInfo($structureId, $token);
            if ($structureInfo && isset($structureInfo['solar_system_id'])) {
                $sourceSystem = $this->solarSystemRepository->find($structureInfo['solar_system_id']);
            }

            if (!$sourceSystem || !$destSystem) {
                $this->logger->warning('Could not resolve solar systems', [
                    'structure_id' => $structureId,
                    'source' => $sourceSystemName,
                    'destination' => $destSystemName,
                ]);
                return 'skipped';
            }
        }

        // Check if gate already exists
        $existing = $this->ansiblexRepository->find($structureId);

        if ($existing) {
            // Update existing gate
            $existing->setName($name);
            $existing->setIsActive(true);
            $existing->touch();
            return 'updated';
        }

        // Create new gate
        $gate = new AnsiblexJumpGate();
        $gate->setStructureId($structureId);
        $gate->setName($name);
        $gate->setSourceSolarSystemId($sourceSystem->getSolarSystemId());
        $gate->setSourceSolarSystemName($sourceSystem->getSolarSystemName());
        $gate->setDestinationSolarSystemId($destSystem->getSolarSystemId());
        $gate->setDestinationSolarSystemName($destSystem->getSolarSystemName());
        $gate->setOwnerAllianceId($allianceId);
        $gate->setIsActive(true);
        $gate->touch();

        // Try to get alliance name
        if ($allianceId) {
            try {
                $allianceInfo = $this->esiClient->getWithCache("/alliances/{$allianceId}/");
                $gate->setOwnerAllianceName($allianceInfo['name'] ?? null);
            } catch (\Exception $e) {
                // Ignore, alliance name is optional
            }
        }

        $this->entityManager->persist($gate);

        return 'added';
    }

    private function parseAnsiblexName(string $name): ?array
    {
        // Common formats:
        // "Jita » Perimeter"
        // "Jita >> Perimeter"
        // "Jita - Perimeter"
        // "SYSTEM-A » SYSTEM-B - Alliance Name"

        // Try different separators
        $separators = [' » ', ' >> ', ' - ', ' « '];

        foreach ($separators as $separator) {
            if (str_contains($name, $separator)) {
                $parts = explode($separator, $name, 2);
                if (count($parts) === 2) {
                    $source = trim($parts[0]);
                    $dest = trim($parts[1]);

                    // Remove any trailing info (like alliance name after another separator)
                    foreach ($separators as $sep) {
                        if (str_contains($dest, $sep)) {
                            $dest = trim(explode($sep, $dest, 2)[0]);
                        }
                    }

                    // Clean up any extra characters
                    $source = preg_replace('/[<>»«\-]+$/', '', $source);
                    $dest = preg_replace('/[<>»«\-]+$/', '', $dest);

                    return [trim($source), trim($dest)];
                }
            }
        }

        return null;
    }

    private function findSolarSystemByName(string $name): ?object
    {
        // Try exact match first
        $system = $this->entityManager->getRepository(\App\Entity\Sde\MapSolarSystem::class)
            ->createQueryBuilder('s')
            ->where('s.solarSystemName = :name')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();

        if ($system) {
            return $system;
        }

        // Try case-insensitive match
        return $this->entityManager->getRepository(\App\Entity\Sde\MapSolarSystem::class)
            ->createQueryBuilder('s')
            ->where('LOWER(s.solarSystemName) = LOWER(:name)')
            ->setParameter('name', $name)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function getStructureInfo(int $structureId, $token): ?array
    {
        try {
            return $this->esiClient->get("/universe/structures/{$structureId}/", $token);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function deactivateNotSeenGates(int $allianceId, array $seenIds): int
    {
        if (empty($seenIds)) {
            return 0;
        }

        $qb = $this->entityManager->createQueryBuilder();
        $qb->update(AnsiblexJumpGate::class, 'a')
            ->set('a.isActive', ':inactive')
            ->set('a.updatedAt', ':now')
            ->where('a.ownerAllianceId = :allianceId')
            ->andWhere('a.isActive = :active')
            ->andWhere($qb->expr()->notIn('a.structureId', ':seenIds'))
            ->setParameter('inactive', false)
            ->setParameter('active', true)
            ->setParameter('allianceId', $allianceId)
            ->setParameter('seenIds', $seenIds)
            ->setParameter('now', new \DateTimeImmutable());

        return $qb->getQuery()->execute();
    }

    /**
     * Sync Ansiblex gates using the search endpoint.
     * This works for ANY character with ACL access to the gates, not just directors.
     *
     * @return array{added: int, updated: int, discovered: int}
     */
    public function syncViaSearch(Character $character): array
    {
        $token = $character->getEveToken();
        if (!$token) {
            throw new \RuntimeException('Character has no EVE token');
        }

        $characterId = $character->getEveCharacterId();
        $allianceId = $character->getAllianceId();

        $this->logger->info('Starting Ansiblex discovery via search', [
            'character' => $character->getName(),
            'character_id' => $characterId,
        ]);

        // Search for structures containing "»" (the Ansiblex naming convention)
        $structureIds = $this->searchAnsiblexStructures($characterId, $token);

        $this->logger->info('Found structures via search', [
            'count' => count($structureIds),
        ]);

        $stats = ['added' => 0, 'updated' => 0, 'discovered' => count($structureIds)];

        foreach ($structureIds as $structureId) {
            $result = $this->processDiscoveredStructure($structureId, $token, $allianceId);
            if ($result === 'added') {
                $stats['added']++;
            } elseif ($result === 'updated') {
                $stats['updated']++;
            }
        }

        $this->entityManager->flush();

        $this->logger->info('Ansiblex discovery completed', $stats);

        return $stats;
    }

    /**
     * Search for Ansiblex structures using the ESI search endpoint.
     * ESI requires minimum 3 characters for search, so we search for " » " with spaces.
     */
    private function searchAnsiblexStructures(int $characterId, $token): array
    {
        $allStructureIds = [];

        // ESI search requires minimum 3 characters
        // Ansiblex names follow the pattern "System A » System B"
        // We'll try multiple search patterns to maximize discovery
        $searchPatterns = [
            ' » ',      // Standard pattern with spaces (3 chars)
            ' >> ',     // Alternative pattern some alliances use
        ];

        foreach ($searchPatterns as $searchQuery) {
            try {
                $endpoint = "/characters/{$characterId}/search/?categories=structure&search=" . urlencode($searchQuery);

                $this->logger->info('Searching for Ansiblex structures', [
                    'character_id' => $characterId,
                    'search_query' => $searchQuery,
                ]);

                $result = $this->esiClient->get($endpoint, $token);

                $structureIds = $result['structure'] ?? [];

                $this->logger->info('Search result', [
                    'search_query' => $searchQuery,
                    'structure_count' => count($structureIds),
                ]);

                $allStructureIds = array_merge($allStructureIds, $structureIds);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to search for Ansiblex structures', [
                    'character_id' => $characterId,
                    'search_query' => $searchQuery,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Remove duplicates
        return array_unique($allStructureIds);
    }

    /**
     * Process a discovered structure ID - get details and save if it's an Ansiblex.
     */
    private function processDiscoveredStructure(int $structureId, $token, ?int $allianceId): string
    {
        try {
            // Check if gate already exists FIRST (before any ESI calls)
            $existing = $this->entityManager->find(AnsiblexJumpGate::class, $structureId);

            if ($existing) {
                // Gate exists, just update it
                try {
                    $structureInfo = $this->esiClient->get("/universe/structures/{$structureId}/", $token);
                    $existing->setName($structureInfo['name'] ?? $existing->getName());
                } catch (\Exception $e) {
                    // Keep existing name if we can't fetch
                }
                $existing->setIsActive(true);
                $existing->touch();
                return 'updated';
            }

            // Get structure details for new gate
            $structureInfo = $this->esiClient->get("/universe/structures/{$structureId}/", $token);

            // Verify it's an Ansiblex Jump Bridge
            if (($structureInfo['type_id'] ?? 0) !== self::ANSIBLEX_TYPE_ID) {
                return 'skipped';
            }

            $name = $structureInfo['name'] ?? '';
            $solarSystemId = $structureInfo['solar_system_id'] ?? null;

            // Parse the name to get source and destination
            $systems = $this->parseAnsiblexName($name);
            if (!$systems) {
                $this->logger->warning('Could not parse Ansiblex name', [
                    'structure_id' => $structureId,
                    'name' => $name,
                ]);
                return 'skipped';
            }

            [$sourceSystemName, $destSystemName] = $systems;

            // Resolve system IDs
            $sourceSystem = $this->findSolarSystemByName($sourceSystemName);
            $destSystem = $this->findSolarSystemByName($destSystemName);

            // If we couldn't resolve source from name, use the solar_system_id from structure info
            if (!$sourceSystem && $solarSystemId) {
                $sourceSystem = $this->solarSystemRepository->find($solarSystemId);
            }

            if (!$sourceSystem || !$destSystem) {
                $this->logger->warning('Could not resolve solar systems for discovered gate', [
                    'structure_id' => $structureId,
                    'name' => $name,
                    'source' => $sourceSystemName,
                    'destination' => $destSystemName,
                ]);
                return 'skipped';
            }

            // Get owner alliance from structure info
            $ownerId = $structureInfo['owner_id'] ?? null;
            $ownerAllianceId = $allianceId; // Use character's alliance as fallback

            // Create new gate
            $gate = new AnsiblexJumpGate();
            $gate->setStructureId($structureId);
            $gate->setName($name);
            $gate->setSourceSolarSystemId($sourceSystem->getSolarSystemId());
            $gate->setSourceSolarSystemName($sourceSystem->getSolarSystemName());
            $gate->setDestinationSolarSystemId($destSystem->getSolarSystemId());
            $gate->setDestinationSolarSystemName($destSystem->getSolarSystemName());
            $gate->setOwnerAllianceId($ownerAllianceId);
            $gate->setIsActive(true);
            $gate->touch();

            // Try to get owner info (could be corp or alliance)
            if ($ownerId) {
                try {
                    // First try alliance
                    $allianceInfo = $this->esiClient->getWithCache("/alliances/{$ownerId}/");
                    $gate->setOwnerAllianceId($ownerId);
                    $gate->setOwnerAllianceName($allianceInfo['name'] ?? null);
                } catch (\Exception $e) {
                    // Owner might be a corporation, try to get its alliance
                    try {
                        $corpInfo = $this->esiClient->getWithCache("/corporations/{$ownerId}/");
                        if (isset($corpInfo['alliance_id'])) {
                            $gate->setOwnerAllianceId($corpInfo['alliance_id']);
                            $allianceInfo = $this->esiClient->getWithCache("/alliances/{$corpInfo['alliance_id']}/");
                            $gate->setOwnerAllianceName($allianceInfo['name'] ?? null);
                        }
                    } catch (\Exception $e2) {
                        // Ignore, alliance info is optional
                    }
                }
            }

            $this->entityManager->persist($gate);

            return 'added';
        } catch (\Exception $e) {
            $this->logger->warning('Failed to process discovered structure', [
                'structure_id' => $structureId,
                'error' => $e->getMessage(),
            ]);
            return 'skipped';
        }
    }

    public function canSync(Character $character): bool
    {
        return $this->hasRequiredScopes($character, ['esi-corporations.read_structures.v1']);
    }

    /**
     * Check if character can use the search-based discovery method.
     * This requires different scopes than the corporation structures method.
     */
    public function canSyncViaSearch(Character $character): bool
    {
        return $this->hasRequiredScopes($character, [
            'esi-search.search_structures.v1',
            'esi-universe.read_structures.v1',
        ]);
    }

    private function hasRequiredScopes(Character $character, array $requiredScopes): bool
    {
        $token = $character->getEveToken();
        if ($token === null) {
            return false;
        }

        $user = $character->getUser();
        if ($user === null || $user->getAuthStatus() !== 'valid') {
            return false;
        }

        return $token->hasAllScopes($requiredScopes);
    }

    public function shouldSync(Character $character): bool
    {
        // Always allow manual sync
        // For scheduled sync, check last sync time
        $lastSync = $character->getLastSyncAt();
        if (!$lastSync) {
            return true;
        }

        $hoursSinceSync = (time() - $lastSync->getTimestamp()) / 3600;
        return $hoursSinceSync >= self::SYNC_INTERVAL_HOURS;
    }
}
