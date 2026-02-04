<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Repository\UserLedgerSettingsRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\MarketService;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class MiningSyncService
{
    private const SYNC_INTERVAL_MINUTES = 30;
    private const DEFAULT_DAYS_TO_SYNC = 30;

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
        private readonly InvTypeRepository $invTypeRepository,
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
            if ($token !== null && $token->getRefreshTokenEncrypted() !== null) {
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
            $this->mercurePublisher->syncStarted($userId, 'mining', 'Synchronisation du mining ledger...');
        }

        try {
            if ($userId !== null) {
                $this->mercurePublisher->syncProgress($userId, 'mining', 10, 'Récupération des données de minage...');
            }

            // Sync mining entries from all characters
            foreach ($user->getCharacters() as $character) {
                $token = $character->getEveToken();
                if ($token === null) {
                    continue;
                }

                try {
                    if ($token->isExpiringSoon()) {
                        $this->tokenManager->refreshAccessToken($token);
                    }

                    $characterId = $character->getEveCharacterId();
                    $characterName = $character->getName();

                    // Get mining ledger from ESI
                    $entries = $this->esiClient->get(
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
                $this->mercurePublisher->syncProgress($userId, 'mining', 70, 'Mise à jour des prix Jita...');
            }
            $results['pricesUpdated'] = $this->updatePrices($user);

        } catch (\Throwable $e) {
            $results['errors'][] = 'Global: ' . $e->getMessage();
            $this->logger->error('Failed to sync mining', ['error' => $e->getMessage()]);
        }

        // Update last sync time
        $settings = $this->settingsRepository->getOrCreate($user);
        $settings->setLastMiningSyncAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Notify sync completed
        if ($userId !== null) {
            $message = sprintf(
                '%d importés, %d mis à jour, %d prix actualisés',
                $results['imported'],
                $results['updated'],
                $results['pricesUpdated']
            );
            $this->mercurePublisher->syncCompleted($userId, 'mining', $message, [
                'imported' => $results['imported'],
                'updated' => $results['updated'],
                'pricesUpdated' => $results['pricesUpdated'],
                'errors' => count($results['errors']),
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
            // Update quantity if changed (ESI can update within the same day)
            if ($existing->getQuantity() !== $quantity) {
                $existing->setQuantity($quantity);
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
            $type = $this->invTypeRepository->find($typeId);
            $this->typeNameCache[$typeId] = $type?->getTypeName() ?? "Type #{$typeId}";
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

    /**
     * Refresh prices for all entries (not just those without prices).
     */
    public function refreshAllPrices(User $user): int
    {
        // Get all unique type IDs
        $results = $this->entityManager->createQuery(
            'SELECT DISTINCT m.typeId FROM App\Entity\MiningEntry m WHERE m.user = :user'
        )
            ->setParameter('user', $user)
            ->getScalarResult();

        $typeIds = array_map(fn($r) => (int) $r['typeId'], $results);

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
}
