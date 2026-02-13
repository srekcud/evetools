<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Entity\PveIncome;
use App\Entity\PveExpense;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Mercure\MercurePublisherService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PveSyncService
{
    private const BOUNTY_REF_TYPES = [
        'bounty_prizes' => PveIncome::TYPE_BOUNTY,
        'ess_escrow_transfer' => PveIncome::TYPE_ESS,
        'agent_mission_reward' => PveIncome::TYPE_MISSION,
        'agent_mission_time_bonus_reward' => PveIncome::TYPE_MISSION,
    ];

    private const SYNC_INTERVAL_MINUTES = 15;
    private const DEFAULT_DAYS_TO_SYNC = 30;

    /** @var array<int, string> */
    private array $typeNameCache = [];

    public function __construct(
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    private function getTypeName(int $typeId): string
    {
        if (!isset($this->typeNameCache[$typeId])) {
            $type = $this->invTypeRepository->find($typeId);
            $this->typeNameCache[$typeId] = $type?->getTypeName() ?? "Type #{$typeId}";
        }
        return $this->typeNameCache[$typeId];
    }

    public function shouldSync(User $user): bool
    {
        $settings = $this->settingsRepository->findByUser($user);

        if ($settings === null || !$settings->isAutoSyncEnabled()) {
            return false;
        }

        $lastSync = $settings->getLastSyncAt();
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

    public function syncAll(User $user): array
    {
        $userId = $user->getId()?->toRfc4122();
        $results = [
            'bounties' => 0,
            'lootSales' => 0,
            'lootContracts' => 0,
            'expenses' => 0,
            'errors' => [],
        ];

        // Notify sync started
        if ($userId !== null) {
            $this->mercurePublisher->syncStarted($userId, 'pve', 'Synchronisation des données PVE...');
        }

        try {
            try {
                if ($userId !== null) {
                    $this->mercurePublisher->syncProgress($userId, 'pve', 10, 'Récupération des bounties...');
                }
                $results['bounties'] = $this->syncWalletJournal($user);
            } catch (\Throwable $e) {
                $results['errors'][] = 'bounties: ' . $e->getMessage();
                $this->logger->error('Failed to sync bounties', ['error' => $e->getMessage()]);
            }

            try {
                if ($userId !== null) {
                    $this->mercurePublisher->syncProgress($userId, 'pve', 35, 'Récupération des ventes de loot...');
                }
                $results['lootSales'] = $this->syncLootSalesFromTransactions($user);
            } catch (\Throwable $e) {
                $results['errors'][] = 'lootSales: ' . $e->getMessage();
                $this->logger->error('Failed to sync loot sales', ['error' => $e->getMessage()]);
            }

            try {
                if ($userId !== null) {
                    $this->mercurePublisher->syncProgress($userId, 'pve', 60, 'Analyse des contrats de loot...');
                }
                $results['lootContracts'] = $this->syncLootFromContracts($user);
            } catch (\Throwable $e) {
                $results['errors'][] = 'lootContracts: ' . $e->getMessage();
                $this->logger->error('Failed to sync loot contracts', ['error' => $e->getMessage()]);
            }

            try {
                if ($userId !== null) {
                    $this->mercurePublisher->syncProgress($userId, 'pve', 85, 'Récupération des dépenses...');
                }
                $results['expenses'] = $this->syncExpensesFromTransactions($user);
            } catch (\Throwable $e) {
                $results['errors'][] = 'expenses: ' . $e->getMessage();
                $this->logger->error('Failed to sync expenses', ['error' => $e->getMessage()]);
            }

            // Update last sync time
            $settings = $this->settingsRepository->getOrCreate($user);
            $settings->setLastSyncAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            // Notify sync completed
            if ($userId !== null) {
                $totalImported = $results['bounties'] + $results['lootSales'] + $results['lootContracts'] + $results['expenses'];
                $message = sprintf(
                    '%d bounties, %d ventes, %d contrats, %d dépenses',
                    $results['bounties'],
                    $results['lootSales'],
                    $results['lootContracts'],
                    $results['expenses']
                );
                $this->mercurePublisher->syncCompleted($userId, 'pve', $message, [
                    'bounties' => $results['bounties'],
                    'lootSales' => $results['lootSales'],
                    'lootContracts' => $results['lootContracts'],
                    'expenses' => $results['expenses'],
                    'totalImported' => $totalImported,
                    'errors' => count($results['errors']),
                ]);
            }

            return $results;
        } catch (\Throwable $e) {
            if ($userId !== null) {
                $this->mercurePublisher->syncError($userId, 'pve', $e->getMessage());
            }
            throw $e;
        }
    }

    public function syncWalletJournal(User $user): int
    {
        $importedIds = $this->incomeRepository->getImportedJournalEntryIds($user);
        $importedIdsSet = array_flip($importedIds);

        $from = new \DateTimeImmutable('-' . self::DEFAULT_DAYS_TO_SYNC . ' days');
        $imported = 0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $journal = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/wallet/journal/",
                    $token
                );

                foreach ($journal as $entry) {
                    $refType = $entry['ref_type'] ?? '';
                    if (!isset(self::BOUNTY_REF_TYPES[$refType])) {
                        continue;
                    }

                    $entryId = (int) $entry['id'];
                    if (isset($importedIdsSet[$entryId])) {
                        continue;
                    }

                    $entryDate = new \DateTimeImmutable($entry['date']);
                    if ($entryDate < $from) {
                        continue;
                    }

                    $amount = (float) ($entry['amount'] ?? 0);
                    if ($amount <= 0) {
                        continue;
                    }

                    $income = new PveIncome();
                    $income->setUser($user);
                    $income->setType(self::BOUNTY_REF_TYPES[$refType]);
                    $income->setDescription($this->formatBountyDescription($entry, $character->getName()));
                    $income->setAmount($amount);
                    $income->setDate($entryDate);
                    $income->setJournalEntryId($entryId);

                    $this->entityManager->persist($income);
                    $importedIdsSet[$entryId] = true;
                    $imported++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync journal for character', [
                    'character' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($imported > 0) {
            $this->entityManager->flush();
        }

        $this->logger->info('Synced wallet journal', ['user' => $user->getId(), 'imported' => $imported]);
        return $imported;
    }

    public function syncLootSalesFromTransactions(User $user): int
    {
        $settings = $this->settingsRepository->findByUser($user);
        if ($settings === null) {
            return 0;
        }

        $lootTypeIds = $settings->getLootTypeIds();
        if (empty($lootTypeIds)) {
            return 0;
        }

        $importedIds = $this->incomeRepository->getImportedTransactionIds($user);
        $importedIdsSet = array_flip($importedIds);
        $declinedIds = array_flip($settings->getDeclinedLootSaleTransactionIds());

        $from = new \DateTimeImmutable('-' . self::DEFAULT_DAYS_TO_SYNC . ' days');
        $imported = 0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $transactions = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/wallet/transactions/",
                    $token
                );

                foreach ($transactions as $transaction) {
                    // Only sell transactions
                    if ($transaction['is_buy'] ?? true) {
                        continue;
                    }

                    $typeId = $transaction['type_id'] ?? 0;
                    if (!in_array($typeId, $lootTypeIds, true)) {
                        continue;
                    }

                    $transactionId = (int) $transaction['transaction_id'];
                    if (isset($importedIdsSet[$transactionId]) || isset($declinedIds[$transactionId])) {
                        continue;
                    }

                    $transactionDate = new \DateTimeImmutable($transaction['date']);
                    if ($transactionDate < $from) {
                        continue;
                    }

                    $quantity = (int) ($transaction['quantity'] ?? 1);
                    $unitPrice = (float) ($transaction['unit_price'] ?? 0);
                    $totalAmount = $quantity * $unitPrice;

                    if ($totalAmount <= 0) {
                        continue;
                    }

                    $income = new PveIncome();
                    $income->setUser($user);
                    $income->setType(PveIncome::TYPE_LOOT_SALE);
                    $income->setDescription("{$quantity}x " . $this->getTypeName($typeId));
                    $income->setAmount($totalAmount);
                    $income->setDate($transactionDate);
                    $income->setTransactionId($transactionId);

                    $this->entityManager->persist($income);
                    $importedIdsSet[$transactionId] = true;
                    $imported++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync transactions for character', [
                    'character' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($imported > 0) {
            $this->entityManager->flush();
        }

        $this->logger->info('Synced loot sales', ['user' => $user->getId(), 'imported' => $imported]);
        return $imported;
    }

    public function syncLootFromContracts(User $user): int
    {
        $importedIds = $this->incomeRepository->getImportedContractIds($user);
        $importedIdsSet = array_flip($importedIds);

        $settings = $this->settingsRepository->findByUser($user);
        $declinedIds = array_flip($settings?->getDeclinedContractIds() ?? []);

        // Combine default PVE loot types with user's custom loot types
        $userLootTypeIds = $settings?->getLootTypeIds() ?? [];
        $pveLootTypeIds = array_unique(array_merge(UserPveSettings::PVE_LOOT_TYPE_IDS, $userLootTypeIds));

        $from = new \DateTimeImmutable('-' . self::DEFAULT_DAYS_TO_SYNC . ' days');
        $imported = 0;

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
                $contracts = $this->esiClient->get(
                    "/characters/{$characterId}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $contractId = (int) $contract['contract_id'];

                    if (isset($importedIdsSet[$contractId]) || isset($declinedIds[$contractId])) {
                        continue;
                    }

                    // Only finished item_exchange contracts where we are the issuer
                    if (($contract['type'] ?? '') !== 'item_exchange') {
                        continue;
                    }
                    if (!in_array($contract['status'] ?? '', ['finished', 'completed'], true)) {
                        continue;
                    }
                    if (($contract['issuer_id'] ?? 0) !== $characterId) {
                        continue;
                    }

                    $completedDate = new \DateTimeImmutable($contract['date_completed'] ?? $contract['date_issued']);
                    if ($completedDate < $from) {
                        continue;
                    }

                    // Get contract items
                    $items = $this->esiClient->get(
                        "/characters/{$characterId}/contracts/{$contractId}/items/",
                        $token
                    );

                    // Count loot items (included items that we gave away)
                    $lootCount = 0;
                    $lootNames = [];

                    foreach ($items as $item) {
                        $typeId = $item['type_id'] ?? 0;
                        $isIncluded = $item['is_included'] ?? false;
                        $quantity = (int) ($item['quantity'] ?? 1);

                        if ($isIncluded && in_array($typeId, $pveLootTypeIds, true)) {
                            $lootCount += $quantity;
                            $typeName = $this->getTypeName($typeId);
                            if (!isset($lootNames[$typeName])) {
                                $lootNames[$typeName] = 0;
                            }
                            $lootNames[$typeName] += $quantity;
                        }
                    }

                    if ($lootCount === 0) {
                        continue;
                    }

                    // Only auto-import contracts with a real price set
                    // 0 ISK contracts will be shown in scan-loot-contracts for manual price entry
                    $contractPrice = (float) ($contract['price'] ?? 0);
                    if ($contractPrice <= 0) {
                        continue;
                    }

                    $totalAmount = $contractPrice;

                    // Build description
                    $descParts = [];
                    foreach ($lootNames as $name => $qty) {
                        $descParts[] = "{$qty}x {$name}";
                    }
                    $description = implode(', ', $descParts);
                    if (strlen($description) > 250) {
                        $description = substr($description, 0, 247) . '...';
                    }

                    $income = new PveIncome();
                    $income->setUser($user);
                    $income->setType(PveIncome::TYPE_LOOT_CONTRACT);
                    $income->setDescription($description);
                    $income->setAmount($totalAmount);
                    $income->setDate($completedDate);
                    $income->setContractId($contractId);

                    $this->entityManager->persist($income);
                    $importedIdsSet[$contractId] = true;
                    $imported++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync loot contracts for character', [
                    'character' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($imported > 0) {
            $this->entityManager->flush();
        }

        $this->logger->info('Synced loot contracts', ['user' => $user->getId(), 'imported' => $imported]);
        return $imported;
    }

    public function syncExpensesFromTransactions(User $user): int
    {
        $settings = $this->settingsRepository->findByUser($user);
        if ($settings === null) {
            return 0;
        }

        $ammoTypeIds = $settings->getAmmoTypeIds();
        if (empty($ammoTypeIds)) {
            return 0;
        }

        $importedIds = $this->expenseRepository->getImportedTransactionIds($user);
        $importedIdsSet = array_flip($importedIds);
        $declinedIds = array_flip($settings->getDeclinedTransactionIds());

        $from = new \DateTimeImmutable('-' . self::DEFAULT_DAYS_TO_SYNC . ' days');
        $imported = 0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $transactions = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/wallet/transactions/",
                    $token
                );

                foreach ($transactions as $transaction) {
                    // Only buy transactions
                    if (!($transaction['is_buy'] ?? false)) {
                        continue;
                    }

                    $typeId = $transaction['type_id'] ?? 0;
                    if (!in_array($typeId, $ammoTypeIds, true)) {
                        continue;
                    }

                    $transactionId = (int) $transaction['transaction_id'];
                    if (isset($importedIdsSet[$transactionId]) || isset($declinedIds[$transactionId])) {
                        continue;
                    }

                    $transactionDate = new \DateTimeImmutable($transaction['date']);
                    if ($transactionDate < $from) {
                        continue;
                    }

                    $quantity = (int) ($transaction['quantity'] ?? 1);
                    $unitPrice = (float) ($transaction['unit_price'] ?? 0);
                    $totalAmount = $quantity * $unitPrice;

                    if ($totalAmount <= 0) {
                        continue;
                    }

                    $expense = new PveExpense();
                    $expense->setUser($user);
                    $expense->setType(PveExpense::TYPE_AMMO);
                    $expense->setDescription("{$quantity}x " . $this->getTypeName($typeId));
                    $expense->setAmount($totalAmount);
                    $expense->setDate($transactionDate);
                    $expense->setTransactionId($transactionId);

                    $this->entityManager->persist($expense);
                    $importedIdsSet[$transactionId] = true;
                    $imported++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync expense transactions for character', [
                    'character' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($imported > 0) {
            $this->entityManager->flush();
        }

        $this->logger->info('Synced expenses', ['user' => $user->getId(), 'imported' => $imported]);
        return $imported;
    }

    public function syncExpensesFromContracts(User $user): int
    {
        $settings = $this->settingsRepository->findByUser($user);
        $ammoTypeIds = $settings?->getAmmoTypeIds() ?? [];
        $beaconTypeIds = UserPveSettings::BEACON_TYPE_IDS;
        $allTypeIds = array_merge($ammoTypeIds, $beaconTypeIds);

        $importedIds = $this->expenseRepository->getImportedContractIds($user);
        $importedIdsSet = array_flip($importedIds);
        $declinedIds = array_flip($settings?->getDeclinedContractIds() ?? []);

        $from = new \DateTimeImmutable('-' . self::DEFAULT_DAYS_TO_SYNC . ' days');
        $imported = 0;

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
                $contracts = $this->esiClient->get(
                    "/characters/{$characterId}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $contractId = (int) $contract['contract_id'];

                    if (isset($importedIdsSet[$contractId]) || isset($declinedIds[$contractId])) {
                        continue;
                    }

                    // Only finished item_exchange contracts
                    if (($contract['type'] ?? '') !== 'item_exchange') {
                        continue;
                    }
                    if (!in_array($contract['status'] ?? '', ['finished', 'completed'], true)) {
                        continue;
                    }

                    $completedDate = new \DateTimeImmutable($contract['date_completed'] ?? $contract['date_issued']);
                    if ($completedDate < $from) {
                        continue;
                    }

                    // Get contract items
                    $items = $this->esiClient->get(
                        "/characters/{$characterId}/contracts/{$contractId}/items/",
                        $token
                    );

                    // Check if we received items (we're the acceptor and items are included)
                    $isAcceptor = ($contract['acceptor_id'] ?? 0) === $characterId;
                    $relevantItems = [];

                    foreach ($items as $item) {
                        $typeId = $item['type_id'] ?? 0;
                        $isIncluded = $item['is_included'] ?? false;

                        // If we're acceptor and item is included, we received it (bought it)
                        if ($isAcceptor && $isIncluded && in_array($typeId, $allTypeIds, true)) {
                            $relevantItems[] = $item;
                        }
                    }

                    if (empty($relevantItems)) {
                        continue;
                    }

                    $price = (float) ($contract['price'] ?? 0);
                    if ($price <= 0) {
                        continue;
                    }

                    // Determine expense type
                    $type = PveExpense::TYPE_OTHER;
                    foreach ($relevantItems as $item) {
                        if (in_array($item['type_id'] ?? 0, $beaconTypeIds, true)) {
                            $type = PveExpense::TYPE_CRAB_BEACON;
                            break;
                        }
                        if (in_array($item['type_id'] ?? 0, $ammoTypeIds, true)) {
                            $type = PveExpense::TYPE_AMMO;
                        }
                    }

                    $expense = new PveExpense();
                    $expense->setUser($user);
                    $expense->setType($type);
                    $expense->setDescription("Contract #{$contractId}");
                    $expense->setAmount($price);
                    $expense->setDate($completedDate);
                    $expense->setContractId($contractId);

                    $this->entityManager->persist($expense);
                    $importedIdsSet[$contractId] = true;
                    $imported++;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to sync contracts for character', [
                    'character' => $character->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($imported > 0) {
            $this->entityManager->flush();
        }

        $this->logger->info('Synced contract expenses', ['user' => $user->getId(), 'imported' => $imported]);
        return $imported;
    }

    private function formatBountyDescription(array $entry, string $characterName): string
    {
        $refType = $entry['ref_type'] ?? 'unknown';
        $label = match ($refType) {
            'bounty_prizes' => 'Bounty',
            'ess_escrow_transfer' => 'ESS',
            'agent_mission_reward' => 'Mission Reward',
            'agent_mission_time_bonus_reward' => 'Mission Bonus',
            default => $refType,
        };

        return "{$label} - {$characterName}";
    }
}
