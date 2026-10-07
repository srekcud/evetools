<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\TypeNameResolver;
use App\Service\ESI\EsiClient;
use App\Service\ESI\MarketService;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MiningSyncService
{
    private const SYNC_INTERVAL_MINUTES = 30;

    /** @var array<int, string> */
    private array $typeNameCache = [];

    /** @var array<int, string> */
    private array $solarSystemNameCache = [];

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly MarketService $marketService,
        private readonly MiningEntryRepository $miningEntryRepository,
        private readonly UserLedgerSettingsRepository $settingsRepository,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function shouldSync(User $user): bool
    {
        $settings = $this->settingsRepository->findByUser($user);

        if ($settings === null) {
            return true;
        }

        if (!$settings->isAutoSyncEnabled()) {
            return false;
        }

        $lastSync = $settings->getLastMiningSyncAt();
        if ($lastSync === null) {
            return true;
        }

        $minutesSinceSync = (time() - $lastSync->getTimestamp()) / 60;
        return $minutesSinceSync >= self::SYNC_INTERVAL_MINUTES;
    }

    public function canSync(User $user): bool
    {
        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sync mining data for all characters of a user.
     *
     * @return array{imported: int, updated: int, pricesUpdated: int, errors: string[]}
     */
    public function syncAll(User $user): array
    {
        $userId = $user->getId()?->toRfc4122();
        $results = [
            'imported' => 0,
            'updated' => 0,
            'pricesUpdated' => 0,
            'errors' => [],
        ];

        // Notify sync started
        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'mining', 'Syncing mining ledger...');
        }

        $tokenizedCharacters = 0;
        $failedCharacters = 0;

        try {
            try {
                if ($userId !== null) {
                    $this->mercurePublisher->syncProgress($userId, 'mining', 10, 'Fetching mining data...');
                }

                // Sync mining entries from all characters
                foreach ($user->getCharacters() as $character) {
                    $token = $character->getEveToken();
                    if ($token === null) {
                        continue;
                    }
                    $tokenizedCharacters++;

                    try {
                        $this->tokenManager->getValidAccessToken($token);

                        $characterId = $character->getEveCharacterId();
                        $characterName = $character->getName();

                        // Get mining ledger from ESI
                        $entries = $this->esiClient->getPaginated(
                            "/characters/{$characterId}/mining/",
                            $token
                        );

                        foreach ($entries as $entry) {
                            $result = $this->upsertMiningEntry($user, $characterId, $characterName, $entry);
                            if ($result === 'created') {
                                $results['imported']++;
                            } elseif ($result === 'updated') {
                                $results['updated']++;
                            }
                        }
                    } catch (\Throwable $e) {
                        $failedCharacters++;
                        $results['errors'][] = "Character {$character->getName()}: " . $e->getMessage();
                        $this->logger->warning('Failed to sync mining for character', [
                            'character' => $character->getName(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Flush pending entries
                $this->entityManager->flush();

                // Update prices
                if ($userId !== null) {
                    $this->mercurePublisher->syncProgress($userId, 'mining', 70, 'Updating Jita prices...');
                }
                $results['pricesUpdated'] = $this->updatePrices($user);

            } catch (\Throwable $e) {
                $results['errors'][] = 'Global: ' . $e->getMessage();
                $this->logger->error('Failed to sync mining', ['error' => $e->getMessage()]);

                // Not rethrown: the next scheduled run retries, a Messenger retry would not do better
                return $this->failSync($userId, $e->getMessage(), $results);
            }

            if ($failedCharacters > 0 && $failedCharacters === $tokenizedCharacters) {
                return $this->failSync($userId, sprintf('Sync failed for all %d characters', $tokenizedCharacters), $results);
            }

            // Update last sync time
            $settings = $this->settingsRepository->getOrCreate($user);
            $settings->setLastMiningSyncAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            // Notify sync completed
            if ($userId !== null) {
                $message = sprintf(
                    '%d imported, %d updated, %d prices refreshed',
                    $results['imported'],
                    $results['updated'],
                    $results['pricesUpdated']
                );
                if ($failedCharacters > 0) {
                    $message .= sprintf(' (%d of %d characters failed)', $failedCharacters, $tokenizedCharacters);
                }
                $this->mercurePublisher->syncCompleted($userId, 'mining', $message, [
                    'imported' => $results['imported'],
                    'updated' => $results['updated'],
                    'pricesUpdated' => $results['pricesUpdated'],
                    'errors' => count($results['errors']),
                    'failedCharacters' => $failedCharacters,
                ]);
            }

            $this->logger->info('Mining sync completed', [
                'user' => $user->getId(),
                'imported' => $results['imported'],
                'updated' => $results['updated'],
                'pricesUpdated' => $results['pricesUpdated'],
                'errors' => count($results['errors']),
            ]);

            return $results;
        } catch (\Throwable $e) {
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'mining', $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Reports the failure without touching the last sync time, so the next scheduled run retries.
     *
     * @param array{imported: int, updated: int, pricesUpdated: int, errors: string[]} $results
     * @return array{imported: int, updated: int, pricesUpdated: int, errors: string[]}
     */
    private function failSync(?string $userId, string $errorMessage, array $results): array
    {
        if ($userId !== null) {
            $this->mercurePublisher->syncError($userId, 'mining', $errorMessage);
        }

        return $results;
    }

    /**
     * Upsert a mining entry.
     *
     * @param array{date: string, type_id: int, solar_system_id: int, quantity: int} $esiData
     * @return string 'created', 'updated', or 'unchanged'
     */
    private function upsertMiningEntry(User $user, int $characterId, string $characterName, array $esiData): string
    {
        $date = new \DateTimeImmutable($esiData['date']);
        $typeId = (int) $esiData['type_id'];
        $solarSystemId = (int) $esiData['solar_system_id'];
        $quantity = (int) $esiData['quantity'];

        // Find existing entry
        $existing = $this->miningEntryRepository->findByUniqueKey(
            $user,
            $characterId,
            $date,
            $typeId,
            $solarSystemId
        );

        if ($existing !== null) {
            $changed = false;

            // Update quantity if changed (ESI can update within the same day)
            if ($existing->getQuantity() !== $quantity) {
                $existing->setQuantity($quantity);
                $changed = true;
            }

            // Fix unresolved type names from previous SDE gaps
            if (str_starts_with($existing->getTypeName(), 'Type #')) {
                $resolved = $this->resolveTypeName($typeId);
                if (!str_starts_with($resolved, 'Type #')) {
                    $existing->setTypeName($resolved);
                    $changed = true;
                }
            }

            if ($changed) {
                $existing->setSyncedAt(new \DateTimeImmutable());
                return 'updated';
            }
            return 'unchanged';
        }

        // Create new entry
        $entry = new MiningEntry();
        $entry->setUser($user);
        $entry->setCharacterId($characterId);
        $entry->setCharacterName($characterName);
        $entry->setDate($date);
        $entry->setTypeId($typeId);
        $entry->setTypeName($this->resolveTypeName($typeId));
        $entry->setSolarSystemId($solarSystemId);
        $entry->setSolarSystemName($this->resolveSolarSystemName($solarSystemId));
        $entry->setQuantity($quantity);
        $entry->setUsage(MiningEntry::USAGE_UNKNOWN);

        // Apply default usage if type is in defaultSoldTypeIds
        $settings = $this->settingsRepository->findByUser($user);
        if ($settings !== null && in_array($typeId, $settings->getDefaultSoldTypeIds(), true)) {
            $entry->setUsage(MiningEntry::USAGE_SOLD);
        }

        $this->entityManager->persist($entry);
        return 'created';
    }

    /**
     * Update Jita prices for all mining entries without prices.
     */
    private function updatePrices(User $user): int
    {
        $typeIds = $this->miningEntryRepository->getTypeIdsWithoutPrice($user);

        if (empty($typeIds)) {
            return 0;
        }

        $prices = $this->marketService->getJitaPrices($typeIds);
        $updated = 0;

        foreach ($prices as $typeId => $price) {
            if ($price !== null && $price > 0) {
                $count = $this->miningEntryRepository->updatePriceByTypeId($user, $typeId, $price);
                $updated += $count;
            }
        }

        return $updated;
    }

    private function resolveTypeName(int $typeId): string
    {
        if (!isset($this->typeNameCache[$typeId])) {
            $this->typeNameCache[$typeId] = $this->typeNameResolver->resolve($typeId);
        }
        return $this->typeNameCache[$typeId];
    }

    private function resolveSolarSystemName(int $solarSystemId): string
    {
        if (!isset($this->solarSystemNameCache[$solarSystemId])) {
            $system = $this->solarSystemRepository->find($solarSystemId);
            $this->solarSystemNameCache[$solarSystemId] = $system?->getSolarSystemName() ?? "System #{$solarSystemId}";
        }
        return $this->solarSystemNameCache[$solarSystemId];
    }
}
