<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\PveExpense;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Entity\UserPveSettings;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use App\Service\Sync\PveSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/pve')]
class PveController extends AbstractController
{
    private const BOUNTY_REF_TYPES = [
        'bounty_prizes',
        'ess_escrow_transfer',
        'agent_mission_reward',
        'agent_mission_time_bonus_reward',
    ];

    private const BEACON_TYPE_IDS = [
        60244, // CONCORD Rogue Analysis Beacon
    ];

    private const MAX_LOOT_CONTRACTS_PER_SCAN = 50;

    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly PveExpenseRepository $expenseRepository,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly PveSyncService $pveSyncService,
    ) {
    }

    #[Route('/income', name: 'api_pve_income', methods: ['GET'])]
    public function getIncome(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        // Parse date range (default: last 30 days)
        $days = (int) $request->query->get('days', 30);
        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        // Get bounties from DB (synced from ESI)
        $bountyEntries = $this->incomeRepository->findBountiesByUserAndDateRange($user, $from, $to);
        $totalBounties = $this->incomeRepository->getTotalBountiesByUserAndDateRange($user, $from, $to);

        $bounties = array_map(fn(PveIncome $i) => [
            'id' => $i->getJournalEntryId() ?? $i->getId()?->toRfc4122(),
            'date' => $i->getDate()->format('c'),
            'refType' => $i->getType(),
            'refTypeLabel' => match($i->getType()) {
                PveIncome::TYPE_BOUNTY => 'Bounty',
                PveIncome::TYPE_ESS => 'ESS',
                PveIncome::TYPE_MISSION => 'Mission',
                default => $i->getType(),
            },
            'amount' => $i->getAmount(),
            'description' => $i->getDescription(),
            'characterName' => $this->extractCharacterName($i->getDescription()),
        ], $bountyEntries);

        // Get expenses
        $totalExpenses = $this->expenseRepository->getTotalByUserAndDateRange($user, $from, $to);
        $expensesByType = $this->expenseRepository->getTotalsByTypeAndDateRange($user, $from, $to);

        // Get loot sales from DB
        $lootSalesList = $this->incomeRepository->findLootSalesByUserAndDateRange($user, $from, $to);
        $totalLootSales = $this->incomeRepository->getTotalLootSalesByUserAndDateRange($user, $from, $to);

        // Get last sync time
        $settings = $this->settingsRepository->findByUser($user);
        $lastSyncAt = $settings?->getLastSyncAt();

        return new JsonResponse([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'days' => $days,
            ],
            'lastSyncAt' => $lastSyncAt?->format('c'),
            'bounties' => [
                'total' => $totalBounties,
                'count' => count($bountyEntries),
                'entries' => $bounties,
            ],
            'lootSales' => [
                'total' => $totalLootSales,
                'count' => count($lootSalesList),
                'entries' => array_map(fn(PveIncome $i) => [
                    'id' => $i->getId()?->toRfc4122(),
                    'type' => $i->getType(),
                    'description' => $i->getDescription(),
                    'amount' => $i->getAmount(),
                    'date' => $i->getDate()->format('Y-m-d'),
                ], $lootSalesList),
            ],
            'expenses' => [
                'total' => $totalExpenses,
                'byType' => $expensesByType,
            ],
            'profit' => $totalBounties + $totalLootSales - $totalExpenses,
        ]);
    }

    /**
     * Extract character name from bounty description (format: "Bounty - CharacterName")
     */
    private function extractCharacterName(string $description): string
    {
        $parts = explode(' - ', $description);
        return $parts[1] ?? $parts[0];
    }

    #[Route('/sync', name: 'api_pve_sync', methods: ['POST'])]
    public function syncPveData(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->pveSyncService->canSync($user)) {
            return new JsonResponse([
                'error' => 'No valid ESI tokens available for sync',
            ], Response::HTTP_BAD_REQUEST);
        }

        $results = $this->pveSyncService->syncAll($user);

        $settings = $this->settingsRepository->findByUser($user);

        return new JsonResponse([
            'status' => 'success',
            'message' => sprintf(
                'Synced %d bounties, %d loot sales, %d loot contracts, %d expenses',
                $results['bounties'],
                $results['lootSales'],
                $results['lootContracts'],
                $results['expenses']
            ),
            'imported' => $results,
            'lastSyncAt' => $settings?->getLastSyncAt()?->format('c'),
            'errors' => $results['errors'],
        ]);
    }

    #[Route('/expenses', name: 'api_pve_expenses', methods: ['GET'])]
    public function getExpenses(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 30);
        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        $expenses = $this->expenseRepository->findByUserAndDateRange($user, $from, $to);

        return new JsonResponse([
            'expenses' => array_map(fn(PveExpense $e) => [
                'id' => $e->getId()?->toRfc4122(),
                'type' => $e->getType(),
                'description' => $e->getDescription(),
                'amount' => $e->getAmount(),
                'date' => $e->getDate()->format('Y-m-d'),
            ], $expenses),
        ]);
    }

    #[Route('/expenses', name: 'api_pve_expenses_create', methods: ['POST'])]
    public function createExpense(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['type'], $data['description'], $data['amount'])) {
            return new JsonResponse(['error' => 'missing_fields'], Response::HTTP_BAD_REQUEST);
        }

        $validTypes = [PveExpense::TYPE_FUEL, PveExpense::TYPE_AMMO, PveExpense::TYPE_CRAB_BEACON, PveExpense::TYPE_OTHER];
        if (!in_array($data['type'], $validTypes, true)) {
            return new JsonResponse(['error' => 'invalid_type'], Response::HTTP_BAD_REQUEST);
        }

        $expense = new PveExpense();
        $expense->setUser($user);
        $expense->setType($data['type']);
        $expense->setDescription($data['description']);
        $expense->setAmount((float) $data['amount']);

        if (isset($data['date'])) {
            $expense->setDate(new \DateTimeImmutable($data['date']));
        }

        $this->entityManager->persist($expense);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $expense->getId()?->toRfc4122(),
            'type' => $expense->getType(),
            'description' => $expense->getDescription(),
            'amount' => $expense->getAmount(),
            'date' => $expense->getDate()->format('Y-m-d'),
        ], Response::HTTP_CREATED);
    }

    #[Route('/expenses/{id}', name: 'api_pve_expenses_delete', methods: ['DELETE'])]
    public function deleteExpense(string $id): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $expense = $this->expenseRepository->find($id);

        if ($expense === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if ($expense->getUser() !== $user) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($expense);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Expense deleted']);
    }

    #[Route('/settings', name: 'api_pve_settings', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $settings = $this->settingsRepository->getOrCreate($user);
        $ammoTypes = [];

        foreach ($settings->getAmmoTypeIds() as $typeId) {
            $type = $this->invTypeRepository->find($typeId);
            $ammoTypes[] = [
                'typeId' => $typeId,
                'typeName' => $type?->getTypeName() ?? "Type #{$typeId}",
            ];
        }

        $lootTypes = [];
        foreach ($settings->getLootTypeIds() as $typeId) {
            $type = $this->invTypeRepository->find($typeId);
            $lootTypes[] = [
                'typeId' => $typeId,
                'typeName' => $type?->getTypeName() ?? "Type #{$typeId}",
            ];
        }

        return new JsonResponse([
            'ammoTypes' => $ammoTypes,
            'lootTypes' => $lootTypes,
        ]);
    }

    #[Route('/settings/reset-declined', name: 'api_pve_settings_reset_declined', methods: ['POST'])]
    public function resetDeclined(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $keepContractIds = array_map('intval', $data['keepContractIds'] ?? []);
        $keepTransactionIds = array_map('intval', $data['keepTransactionIds'] ?? []);

        $settings = $this->settingsRepository->getOrCreate($user);
        $settings->clearDeclinedExcept($keepContractIds, $keepTransactionIds);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/settings/ammo', name: 'api_pve_settings_ammo_add', methods: ['POST'])]
    public function addAmmoType(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $typeId = (int) ($data['typeId'] ?? 0);

        if ($typeId <= 0) {
            return new JsonResponse(['error' => 'invalid_type_id'], Response::HTTP_BAD_REQUEST);
        }

        $type = $this->invTypeRepository->find($typeId);
        if ($type === null) {
            return new JsonResponse(['error' => 'type_not_found'], Response::HTTP_NOT_FOUND);
        }

        $settings = $this->settingsRepository->getOrCreate($user);
        $settings->addAmmoTypeId($typeId);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return new JsonResponse([
            'typeId' => $typeId,
            'typeName' => $type->getTypeName(),
        ]);
    }

    #[Route('/settings/ammo/{typeId}', name: 'api_pve_settings_ammo_remove', methods: ['DELETE'])]
    public function removeAmmoType(int $typeId): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $settings = $this->settingsRepository->getOrCreate($user);
        $settings->removeAmmoTypeId($typeId);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Ammo type removed']);
    }

    #[Route('/settings/loot', name: 'api_pve_settings_loot_add', methods: ['POST'])]
    public function addLootType(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $typeId = (int) ($data['typeId'] ?? 0);

        if ($typeId <= 0) {
            return new JsonResponse(['error' => 'invalid_type_id'], Response::HTTP_BAD_REQUEST);
        }

        $type = $this->invTypeRepository->find($typeId);
        if ($type === null) {
            return new JsonResponse(['error' => 'type_not_found'], Response::HTTP_NOT_FOUND);
        }

        $settings = $this->settingsRepository->getOrCreate($user);
        $settings->addLootTypeId($typeId);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return new JsonResponse([
            'typeId' => $typeId,
            'typeName' => $type->getTypeName(),
        ]);
    }

    #[Route('/settings/loot/{typeId}', name: 'api_pve_settings_loot_remove', methods: ['DELETE'])]
    public function removeLootType(int $typeId): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $settings = $this->settingsRepository->getOrCreate($user);
        $settings->removeLootTypeId($typeId);

        $this->entityManager->persist($settings);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Loot type removed']);
    }

    #[Route('/search-types', name: 'api_pve_search_types', methods: ['GET'])]
    public function searchTypes(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $query = $request->query->get('query', '');
        if (strlen($query) < 2) {
            return new JsonResponse(['types' => []]);
        }

        $types = $this->invTypeRepository->searchByName($query, 20);

        return new JsonResponse([
            'types' => array_map(fn($t) => [
                'typeId' => $t->getTypeId(),
                'typeName' => $t->getTypeName(),
            ], $types),
        ]);
    }

    #[Route('/scan-contracts', name: 'api_pve_scan_contracts', methods: ['POST'])]
    public function scanContracts(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 30);
        $from = new \DateTimeImmutable("-{$days} days");

        $settings = $this->settingsRepository->getOrCreate($user);
        $ammoTypeIds = $settings->getAmmoTypeIds();

        // Get already imported IDs to filter them out
        $importedContractIds = $this->expenseRepository->getImportedContractIds($user);
        $importedTransactionIds = $this->expenseRepository->getImportedTransactionIds($user);

        // Get declined IDs to filter them out
        $declinedContractIds = $settings->getDeclinedContractIds();
        $declinedTransactionIds = $settings->getDeclinedTransactionIds();

        $detectedExpenses = [];
        $scannedContracts = 0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                // Fetch all contracts (paginated)
                $contracts = $this->esiClient->getPaginated(
                    "/characters/{$character->getEveCharacterId()}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $scannedContracts++;

                    // Skip already imported or declined contracts
                    if (in_array($contract['contract_id'], $importedContractIds, true)) {
                        continue;
                    }
                    if (in_array($contract['contract_id'], $declinedContractIds, true)) {
                        continue;
                    }

                    // Only item exchange contracts that are completed/finished
                    if ($contract['type'] !== 'item_exchange') {
                        continue;
                    }
                    if (!in_array($contract['status'] ?? '', ['finished', 'completed'], true)) {
                        continue;
                    }
                    // Only contracts where we paid (either as acceptor or issuer)
                    $isAcceptor = ($contract['acceptor_id'] ?? 0) === $character->getEveCharacterId();
                    $isIssuer = ($contract['issuer_id'] ?? 0) === $character->getEveCharacterId();

                    if (!$isAcceptor && !$isIssuer) {
                        continue;
                    }

                    $contractDate = new \DateTimeImmutable($contract['date_completed'] ?? $contract['date_accepted'] ?? $contract['date_issued']);
                    if ($contractDate < $from) {
                        continue;
                    }

                    // Get contract items
                    try {
                        $items = $this->esiClient->get(
                            "/characters/{$character->getEveCharacterId()}/contracts/{$contract['contract_id']}/items/",
                            $token
                        );
                    } catch (\Throwable) {
                        continue;
                    }

                    // Analyze items: categorize by beacon (CRAB) or user-configured ammo types
                    $beaconItems = [];
                    $ammoItems = [];

                    foreach ($items as $item) {
                        // Determine which items we received based on our role
                        $isIncluded = $item['is_included'] ?? true;
                        $weReceivedItem = ($isAcceptor && $isIncluded) || ($isIssuer && !$isIncluded);

                        if (!$weReceivedItem) {
                            continue;
                        }

                        $typeId = $item['type_id'];
                        $quantity = $item['quantity'] ?? 1;
                        $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}";

                        if (in_array($typeId, self::BEACON_TYPE_IDS, true)) {
                            $beaconItems[] = ['typeId' => $typeId, 'typeName' => $typeName, 'quantity' => $quantity];
                        } elseif (in_array($typeId, $ammoTypeIds, true)) {
                            $ammoItems[] = ['typeId' => $typeId, 'typeName' => $typeName, 'quantity' => $quantity];
                        }
                    }

                    $price = (float) ($contract['price'] ?? 0);
                    if ($price <= 0) {
                        continue;
                    }

                    // Determine expense type: beacon takes priority over ammo
                    $type = null;
                    $allItems = [];

                    if (!empty($beaconItems)) {
                        $type = PveExpense::TYPE_CRAB_BEACON;
                        $allItems = $beaconItems;
                    }
                    if (!empty($ammoItems)) {
                        $type = $type ?? PveExpense::TYPE_AMMO;
                        $allItems = array_merge($allItems, $ammoItems);
                    }

                    if ($type === null || empty($allItems)) {
                        continue;
                    }

                    $description = implode(', ', array_map(
                        fn($i) => "{$i['quantity']}x {$i['typeName']}",
                        $allItems
                    ));

                    $detectedExpenses[] = [
                        'contractId' => $contract['contract_id'],
                        'transactionId' => 0,
                        'type' => $type,
                        'typeId' => $allItems[0]['typeId'],
                        'typeName' => $description,
                        'quantity' => array_sum(array_column($allItems, 'quantity')),
                        'price' => $price,
                        'dateIssued' => $contractDate->format('Y-m-d'),
                        'source' => 'contract',
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Also scan market transactions
        $scannedTransactions = 0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                // Fetch wallet transactions
                $transactions = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/wallet/transactions/",
                    $token
                );

                foreach ($transactions as $transaction) {
                    $scannedTransactions++;

                    // Skip already imported or declined transactions
                    if (in_array($transaction['transaction_id'], $importedTransactionIds, true)) {
                        continue;
                    }
                    if (in_array($transaction['transaction_id'], $declinedTransactionIds, true)) {
                        continue;
                    }

                    // Only buy transactions
                    if (!($transaction['is_buy'] ?? false)) {
                        continue;
                    }

                    $transactionDate = new \DateTimeImmutable($transaction['date']);
                    if ($transactionDate < $from) {
                        continue;
                    }

                    $typeId = $transaction['type_id'];
                    $quantity = $transaction['quantity'] ?? 1;
                    $unitPrice = (float) ($transaction['unit_price'] ?? 0);
                    $totalPrice = $quantity * $unitPrice;

                    if ($totalPrice <= 0) {
                        continue;
                    }

                    $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}";

                    // Determine expense type: beacon or user-configured ammo
                    $type = match (true) {
                        in_array($typeId, self::BEACON_TYPE_IDS, true) => PveExpense::TYPE_CRAB_BEACON,
                        in_array($typeId, $ammoTypeIds, true) => PveExpense::TYPE_AMMO,
                        default => null,
                    };

                    if ($type !== null) {
                        $detectedExpenses[] = [
                            'contractId' => 0, // Not a contract
                            'transactionId' => $transaction['transaction_id'],
                            'type' => $type,
                            'typeId' => $typeId,
                            'typeName' => "{$quantity}x {$typeName}",
                            'quantity' => $quantity,
                            'price' => $totalPrice,
                            'dateIssued' => $transactionDate->format('Y-m-d'),
                            'source' => 'market',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return new JsonResponse([
            'scannedContracts' => $scannedContracts,
            'scannedTransactions' => $scannedTransactions,
            'detectedExpenses' => $detectedExpenses,
        ]);
    }

    #[Route('/import-expenses', name: 'api_pve_import_expenses', methods: ['POST'])]
    public function importExpenses(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $expenses = $data['expenses'] ?? [];
        $declined = $data['declined'] ?? [];

        $imported = 0;

        // Save declined items
        $settings = $this->settingsRepository->getOrCreate($user);
        foreach ($declined as $item) {
            $contractId = isset($item['contractId']) ? (int) $item['contractId'] : 0;
            $transactionId = isset($item['transactionId']) ? (int) $item['transactionId'] : 0;

            if ($contractId > 0) {
                $settings->addDeclinedContractId($contractId);
            }
            if ($transactionId > 0) {
                $settings->addDeclinedTransactionId($transactionId);
            }
        }
        $this->entityManager->persist($settings);

        // Get already imported IDs to prevent duplicates
        $importedContractIds = $this->expenseRepository->getImportedContractIds($user);
        $importedTransactionIds = $this->expenseRepository->getImportedTransactionIds($user);

        foreach ($expenses as $expenseData) {
            if (!isset($expenseData['type'], $expenseData['typeName'], $expenseData['price'], $expenseData['dateIssued'])) {
                continue;
            }

            $contractId = isset($expenseData['contractId']) ? (int) $expenseData['contractId'] : null;
            $transactionId = isset($expenseData['transactionId']) ? (int) $expenseData['transactionId'] : null;

            // Skip if already imported (double-check)
            if ($contractId && in_array($contractId, $importedContractIds, true)) {
                continue;
            }
            if ($transactionId && in_array($transactionId, $importedTransactionIds, true)) {
                continue;
            }

            // typeName already contains the full description with quantities
            $description = $expenseData['typeName'];

            $expense = new PveExpense();
            $expense->setUser($user);
            $expense->setType($expenseData['type']);
            $expense->setDescription($description);
            $expense->setAmount((float) $expenseData['price']);
            $expense->setDate(new \DateTimeImmutable($expenseData['dateIssued']));

            if ($contractId && $contractId > 0) {
                $expense->setContractId($contractId);
            }
            if ($transactionId && $transactionId > 0) {
                $expense->setTransactionId($transactionId);
            }

            $this->entityManager->persist($expense);
            $imported++;

            // Add to lists to prevent duplicates within same import batch
            if ($contractId) {
                $importedContractIds[] = $contractId;
            }
            if ($transactionId) {
                $importedTransactionIds[] = $transactionId;
            }
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'imported' => $imported,
        ]);
    }

    #[Route('/loot-sales', name: 'api_pve_loot_sales', methods: ['GET'])]
    public function getLootSales(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 30);
        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        $lootSales = $this->incomeRepository->findByUserAndDateRange($user, $from, $to);

        return new JsonResponse([
            'lootSales' => array_map(fn(PveIncome $i) => [
                'id' => $i->getId()?->toRfc4122(),
                'type' => $i->getType(),
                'description' => $i->getDescription(),
                'amount' => $i->getAmount(),
                'date' => $i->getDate()->format('Y-m-d'),
            ], $lootSales),
        ]);
    }

    #[Route('/loot-sales', name: 'api_pve_loot_sales_create', methods: ['POST'])]
    public function createLootSale(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['description'], $data['amount'])) {
            return new JsonResponse(['error' => 'missing_fields'], Response::HTTP_BAD_REQUEST);
        }

        $income = new PveIncome();
        $income->setUser($user);
        $income->setType($data['type'] ?? PveIncome::TYPE_LOOT_SALE);
        $income->setDescription($data['description']);
        $income->setAmount((float) $data['amount']);

        if (isset($data['date'])) {
            $income->setDate(new \DateTimeImmutable($data['date']));
        }

        $this->entityManager->persist($income);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $income->getId()?->toRfc4122(),
            'type' => $income->getType(),
            'description' => $income->getDescription(),
            'amount' => $income->getAmount(),
            'date' => $income->getDate()->format('Y-m-d'),
        ], Response::HTTP_CREATED);
    }

    #[Route('/loot-sales/{id}', name: 'api_pve_loot_sales_delete', methods: ['DELETE'])]
    public function deleteLootSale(string $id): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $lootSale = $this->incomeRepository->find($id);

        if ($lootSale === null) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if ($lootSale->getUser() !== $user) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        // Add to declined list so it doesn't reappear on next scan
        $settings = $this->settingsRepository->getOrCreate($user);
        if ($lootSale->getContractId() !== null) {
            $settings->addDeclinedContractId($lootSale->getContractId());
        }
        if ($lootSale->getTransactionId() !== null) {
            $settings->addDeclinedLootSaleTransactionId($lootSale->getTransactionId());
        }
        $this->entityManager->persist($settings);

        $this->entityManager->remove($lootSale);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Loot sale deleted']);
    }

    #[Route('/scan-loot-sales', name: 'api_pve_scan_loot_sales', methods: ['POST'])]
    public function scanLootSales(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 30);
        $from = new \DateTimeImmutable("-{$days} days");

        $settings = $this->settingsRepository->getOrCreate($user);
        $lootTypeIds = $settings->getLootTypeIds();

        // If no loot types configured, return empty
        if (empty($lootTypeIds)) {
            return new JsonResponse([
                'scannedTransactions' => 0,
                'detectedSales' => [],
                'noLootTypesConfigured' => true,
            ]);
        }

        // Get already imported IDs to filter them out
        $importedTransactionIds = $this->incomeRepository->getImportedTransactionIds($user);

        // Get declined IDs to filter them out
        $declinedTransactionIds = $settings->getDeclinedLootSaleTransactionIds();

        $detectedSales = [];
        $scannedTransactions = 0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                // Fetch wallet transactions
                $transactions = $this->esiClient->get(
                    "/characters/{$character->getEveCharacterId()}/wallet/transactions/",
                    $token
                );

                foreach ($transactions as $transaction) {
                    $scannedTransactions++;

                    // Skip already imported or declined transactions
                    if (in_array($transaction['transaction_id'], $importedTransactionIds, true)) {
                        continue;
                    }
                    if (in_array($transaction['transaction_id'], $declinedTransactionIds, true)) {
                        continue;
                    }

                    // Only sell transactions (is_buy = false)
                    if ($transaction['is_buy'] ?? true) {
                        continue;
                    }

                    $transactionDate = new \DateTimeImmutable($transaction['date']);
                    if ($transactionDate < $from) {
                        continue;
                    }

                    $typeId = $transaction['type_id'];

                    // Only configured loot types
                    if (!in_array($typeId, $lootTypeIds, true)) {
                        continue;
                    }

                    $quantity = $transaction['quantity'] ?? 1;
                    $unitPrice = (float) ($transaction['unit_price'] ?? 0);
                    $totalPrice = $quantity * $unitPrice;

                    if ($totalPrice <= 0) {
                        continue;
                    }

                    $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}";

                    $detectedSales[] = [
                        'transactionId' => $transaction['transaction_id'],
                        'contractId' => 0,
                        'type' => PveIncome::TYPE_LOOT_SALE,
                        'typeId' => $typeId,
                        'typeName' => "{$quantity}x {$typeName}",
                        'quantity' => $quantity,
                        'price' => $totalPrice,
                        'dateIssued' => $transactionDate->format('Y-m-d'),
                        'characterName' => $character->getName(),
                        'source' => 'market',
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        // Also scan contracts for loot sales
        $scannedContracts = 0;

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                // Fetch all contracts (paginated)
                $contracts = $this->esiClient->getPaginated(
                    "/characters/{$character->getEveCharacterId()}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $scannedContracts++;

                    // Only item exchange contracts that are completed/finished
                    if ($contract['type'] !== 'item_exchange') {
                        continue;
                    }
                    if (!in_array($contract['status'] ?? '', ['finished', 'completed'], true)) {
                        continue;
                    }

                    // Check if we are issuer or acceptor
                    $isAcceptor = ($contract['acceptor_id'] ?? 0) === $character->getEveCharacterId();
                    $isIssuer = ($contract['issuer_id'] ?? 0) === $character->getEveCharacterId();

                    if (!$isAcceptor && !$isIssuer) {
                        continue;
                    }

                    $contractDate = new \DateTimeImmutable($contract['date_completed'] ?? $contract['date_accepted'] ?? $contract['date_issued']);
                    if ($contractDate < $from) {
                        continue;
                    }

                    // We need to have received ISK (price > 0 for issuer, or reward > 0 for acceptor)
                    $price = (float) ($contract['price'] ?? 0);
                    $reward = (float) ($contract['reward'] ?? 0);

                    // Determine if we received ISK
                    $iskReceived = 0;
                    if ($isIssuer && $price > 0) {
                        // We created a sell contract and received the price
                        $iskReceived = $price;
                    } elseif ($isAcceptor && $reward > 0) {
                        // We accepted a contract with a reward
                        $iskReceived = $reward;
                    }

                    if ($iskReceived <= 0) {
                        continue;
                    }

                    // Create a unique ID for tracking (use negative contract_id to distinguish from transactions)
                    $trackingId = -$contract['contract_id'];

                    // Skip already imported or declined
                    if (in_array($trackingId, $importedTransactionIds, true)) {
                        continue;
                    }
                    if (in_array($trackingId, $declinedTransactionIds, true)) {
                        continue;
                    }

                    // Get contract items
                    try {
                        $items = $this->esiClient->get(
                            "/characters/{$character->getEveCharacterId()}/contracts/{$contract['contract_id']}/items/",
                            $token
                        );
                    } catch (\Throwable) {
                        continue;
                    }

                    // Check if we sold configured loot items
                    $soldLootItems = [];

                    foreach ($items as $item) {
                        // Determine which items we gave away based on our role
                        // - As issuer: we give items where is_included = true
                        // - As acceptor: we give items where is_included = false
                        $isIncluded = $item['is_included'] ?? true;
                        $weGaveItem = ($isIssuer && $isIncluded) || ($isAcceptor && !$isIncluded);

                        if (!$weGaveItem) {
                            continue;
                        }

                        $typeId = $item['type_id'];
                        if (!in_array($typeId, $lootTypeIds, true)) {
                            continue;
                        }

                        $quantity = $item['quantity'] ?? 1;
                        $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}";

                        $soldLootItems[] = ['typeId' => $typeId, 'typeName' => $typeName, 'quantity' => $quantity];
                    }

                    if (empty($soldLootItems)) {
                        continue;
                    }

                    // Create description from all items
                    $description = implode(', ', array_map(
                        fn($i) => "{$i['quantity']}x {$i['typeName']}",
                        $soldLootItems
                    ));

                    $firstItem = $soldLootItems[0];

                    $detectedSales[] = [
                        'transactionId' => $trackingId,
                        'contractId' => $contract['contract_id'],
                        'type' => PveIncome::TYPE_LOOT_SALE,
                        'typeId' => $firstItem['typeId'],
                        'typeName' => $description,
                        'quantity' => array_sum(array_column($soldLootItems, 'quantity')),
                        'price' => $iskReceived,
                        'dateIssued' => $contractDate->format('Y-m-d'),
                        'characterName' => $character->getName(),
                        'source' => 'contract',
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return new JsonResponse([
            'scannedTransactions' => $scannedTransactions,
            'scannedContracts' => $scannedContracts,
            'detectedSales' => $detectedSales,
        ]);
    }

    #[Route('/import-loot-sales', name: 'api_pve_import_loot_sales', methods: ['POST'])]
    public function importLootSales(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $sales = $data['sales'] ?? [];
        $declined = $data['declined'] ?? [];

        $imported = 0;

        // Save declined items
        $settings = $this->settingsRepository->getOrCreate($user);
        foreach ($declined as $item) {
            $transactionId = isset($item['transactionId']) ? (int) $item['transactionId'] : 0;

            if ($transactionId > 0) {
                $settings->addDeclinedLootSaleTransactionId($transactionId);
            }
        }
        $this->entityManager->persist($settings);

        // Get already imported IDs to prevent duplicates
        $importedTransactionIds = $this->incomeRepository->getImportedTransactionIds($user);

        foreach ($sales as $saleData) {
            if (!isset($saleData['typeName'], $saleData['price'], $saleData['dateIssued'])) {
                continue;
            }

            $transactionId = isset($saleData['transactionId']) ? (int) $saleData['transactionId'] : null;

            // Skip if already imported (double-check)
            if ($transactionId && in_array($transactionId, $importedTransactionIds, true)) {
                continue;
            }

            $income = new PveIncome();
            $income->setUser($user);
            $income->setType($saleData['type'] ?? PveIncome::TYPE_LOOT_SALE);
            $income->setDescription($saleData['typeName']);
            $income->setAmount((float) $saleData['price']);
            $income->setDate(new \DateTimeImmutable($saleData['dateIssued']));

            if ($transactionId && $transactionId > 0) {
                $income->setTransactionId($transactionId);
            }

            $this->entityManager->persist($income);
            $imported++;

            // Add to list to prevent duplicates within same import batch
            if ($transactionId) {
                $importedTransactionIds[] = $transactionId;
            }
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'imported' => $imported,
        ]);
    }

    #[Route('/scan-loot-contracts', name: 'api_pve_scan_loot_contracts', methods: ['POST'])]
    public function scanLootContracts(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 30);
        $from = new \DateTimeImmutable("-{$days} days");

        // Combine default PVE loot types with user's custom loot types
        $settings = $this->settingsRepository->findByUser($user);
        $userLootTypeIds = $settings?->getLootTypeIds() ?? [];
        $pveLootTypeIds = array_unique(array_merge(UserPveSettings::PVE_LOOT_TYPE_IDS, $userLootTypeIds));
        $defaultPricePerItem = UserPveSettings::PVE_LOOT_DEFAULT_PRICE_PER_ITEM;

        // Get already imported contract IDs
        $importedContractIds = $this->incomeRepository->getImportedContractIds($user);

        // Get declined IDs
        $settings = $this->settingsRepository->findByUser($user);
        $declinedContractIds = $settings?->getDeclinedContractIds() ?? [];

        $detectedContracts = [];
        $scannedContracts = 0;

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

                // Scan personal contracts
                $contracts = $this->esiClient->get(
                    "/characters/{$characterId}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $scannedContracts++;

                    // Stop if we've found enough contracts
                    if (count($detectedContracts) >= self::MAX_LOOT_CONTRACTS_PER_SCAN) {
                        break;
                    }

                    $contractId = (int) $contract['contract_id'];

                    // Skip already imported or declined
                    if (in_array($contractId, $importedContractIds, true)) {
                        continue;
                    }
                    if (in_array($contractId, $declinedContractIds, true)) {
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

                    $contractPrice = (float) ($contract['price'] ?? 0);

                    $completedDate = new \DateTimeImmutable($contract['date_completed'] ?? $contract['date_issued']);
                    if ($completedDate < $from) {
                        continue;
                    }

                    // Get contract items
                    try {
                        $items = $this->esiClient->get(
                            "/characters/{$characterId}/contracts/{$contractId}/items/",
                            $token
                        );
                    } catch (\Throwable) {
                        continue;
                    }

                    // Count loot items
                    $lootItems = [];
                    $totalQuantity = 0;

                    foreach ($items as $item) {
                        $typeId = $item['type_id'] ?? 0;
                        $isIncluded = $item['is_included'] ?? false;
                        $quantity = (int) ($item['quantity'] ?? 1);

                        if ($isIncluded && in_array($typeId, $pveLootTypeIds, true)) {
                            $typeName = $this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}";
                            $lootItems[] = [
                                'typeId' => $typeId,
                                'typeName' => $typeName,
                                'quantity' => $quantity,
                            ];
                            $totalQuantity += $quantity;
                        }
                    }

                    if (empty($lootItems)) {
                        continue;
                    }

                    // Build description
                    $description = implode(', ', array_map(
                        fn($i) => "{$i['quantity']}x {$i['typeName']}",
                        $lootItems
                    ));

                    $detectedContracts[] = [
                        'contractId' => $contractId,
                        'description' => $description,
                        'items' => $lootItems,
                        'totalQuantity' => $totalQuantity,
                        'contractPrice' => $contractPrice,
                        'suggestedPrice' => $contractPrice > 0 ? $contractPrice : $totalQuantity * $defaultPricePerItem,
                        'date' => $completedDate->format('Y-m-d'),
                        'characterName' => $character->getName(),
                    ];
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return new JsonResponse([
            'scannedContracts' => $scannedContracts,
            'detectedContracts' => $detectedContracts,
            'defaultPricePerItem' => $defaultPricePerItem,
            'hasMore' => count($detectedContracts) >= self::MAX_LOOT_CONTRACTS_PER_SCAN,
            'maxPerScan' => self::MAX_LOOT_CONTRACTS_PER_SCAN,
        ]);
    }

    #[Route('/import-loot-contracts', name: 'api_pve_import_loot_contracts', methods: ['POST'])]
    public function importLootContracts(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $contracts = $data['contracts'] ?? [];
        $declined = $data['declined'] ?? [];

        $imported = 0;

        // Save declined contracts
        $settings = $this->settingsRepository->getOrCreate($user);
        foreach ($declined as $item) {
            $contractId = isset($item['contractId']) ? (int) $item['contractId'] : 0;
            if ($contractId > 0) {
                $settings->addDeclinedContractId($contractId);
            }
        }
        $this->entityManager->persist($settings);

        // Get already imported IDs to prevent duplicates
        $importedContractIds = $this->incomeRepository->getImportedContractIds($user);

        $rejectedZeroPrice = 0;

        foreach ($contracts as $contractData) {
            if (!isset($contractData['contractId'], $contractData['price'], $contractData['description'], $contractData['date'])) {
                continue;
            }

            $contractId = (int) $contractData['contractId'];
            $price = (float) $contractData['price'];

            // Skip if already imported
            if (in_array($contractId, $importedContractIds, true)) {
                continue;
            }

            // Reject if price is 0 or negative - add to declined so it doesn't reappear
            if ($price <= 0) {
                $settings->addDeclinedContractId($contractId);
                $rejectedZeroPrice++;
                continue;
            }

            $description = $contractData['description'];
            if (strlen($description) > 250) {
                $description = substr($description, 0, 247) . '...';
            }

            $income = new PveIncome();
            $income->setUser($user);
            $income->setType(PveIncome::TYPE_LOOT_CONTRACT);
            $income->setDescription($description);
            $income->setAmount($price);
            $income->setDate(new \DateTimeImmutable($contractData['date']));
            $income->setContractId($contractId);

            $this->entityManager->persist($income);
            $importedContractIds[] = $contractId;
            $imported++;
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'imported' => $imported,
            'rejectedZeroPrice' => $rejectedZeroPrice,
        ]);
    }

    #[Route('/stats', name: 'api_pve_stats', methods: ['GET'])]
    public function getStats(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 30);
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        // Get income totals by type from database
        $incomeByType = $this->incomeRepository->getTotalsByType($user, $from, $to);
        $bountyTotal = $incomeByType[PveIncome::TYPE_BOUNTY] ?? 0.0;
        $essTotal = $incomeByType[PveIncome::TYPE_ESS] ?? 0.0;
        $missionTotal = $incomeByType[PveIncome::TYPE_MISSION] ?? 0.0;
        $lootSalesTotal = ($incomeByType[PveIncome::TYPE_LOOT_SALE] ?? 0.0) + ($incomeByType[PveIncome::TYPE_LOOT_CONTRACT] ?? 0.0);

        // Get expenses total
        $expensesTotal = $this->expenseRepository->getTotalByUserAndDateRange($user, $from, $to);

        $totalIncome = $bountyTotal + $essTotal + $missionTotal + $lootSalesTotal;
        $profit = $totalIncome - $expensesTotal;

        // Get expenses by type
        $expensesByType = $this->expenseRepository->getTotalsByTypeAndDateRange($user, $from, $to);

        // Calculate ISK per day (more realistic than ISK/hour without session tracking)
        $iskPerDay = $days > 0 ? $profit / $days : 0;

        return new JsonResponse([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'days' => $days,
            ],
            'totals' => [
                'income' => $totalIncome,
                'bounties' => $bountyTotal,
                'ess' => $essTotal,
                'missions' => $missionTotal,
                'lootSales' => $lootSalesTotal,
                'expenses' => $expensesTotal,
                'profit' => $profit,
            ],
            'expensesByType' => $expensesByType,
            'iskPerDay' => $iskPerDay,
        ]);
    }

    #[Route('/stats/daily', name: 'api_pve_stats_daily', methods: ['GET'])]
    public function getStatsDaily(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 30);
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        // Initialize daily data array
        $dailyData = [];
        $current = $from;
        while ($current <= $to) {
            $dateKey = $current->format('Y-m-d');
            $dailyData[$dateKey] = [
                'date' => $dateKey,
                'income' => 0.0,
                'bounties' => 0.0,
                'lootSales' => 0.0,
                'expenses' => 0.0,
                'profit' => 0.0,
            ];
            $current = $current->modify('+1 day');
        }

        // Get income daily totals by type from database
        // Note: lootSales includes both market sales and contract sales
        $incomeDailyTotals = $this->incomeRepository->getDailyTotalsByType($user, $from, $to);
        foreach ($incomeDailyTotals as $dateKey => $data) {
            if (isset($dailyData[$dateKey])) {
                $dailyData[$dateKey]['bounties'] = $data['bounties'];
                $dailyData[$dateKey]['lootSales'] = $data['lootSales'];
            }
        }

        // Get expenses from database
        $expenseDailyTotals = $this->expenseRepository->getDailyTotals($user, $from, $to);
        foreach ($expenseDailyTotals as $dateKey => $data) {
            if (isset($dailyData[$dateKey])) {
                $dailyData[$dateKey]['expenses'] = $data['total'];
            }
        }

        // Calculate totals and profit for each day
        foreach ($dailyData as &$day) {
            $day['income'] = $day['bounties'] + $day['lootSales'];
            $day['profit'] = $day['income'] - $day['expenses'];
        }
        unset($day);

        return new JsonResponse([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'days' => $days,
            ],
            'daily' => array_values($dailyData),
        ]);
    }

    #[Route('/stats/by-type', name: 'api_pve_stats_by_type', methods: ['GET'])]
    public function getStatsByType(Request $request): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $days = (int) $request->query->get('days', 30);
        $to = new \DateTimeImmutable();
        $from = $to->modify("-{$days} days");

        // Income by type from database
        $incomeByType = $this->incomeRepository->getTotalsByType($user, $from, $to);

        // Expense by type from database
        $expensesByType = $this->expenseRepository->getTotalsByTypeAndDateRange($user, $from, $to);

        // Add bounties from wallet journal
        $bountyTotal = 0.0;
        $essTotal = 0.0;
        $missionTotal = 0.0;

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
                    if (!in_array($refType, self::BOUNTY_REF_TYPES, true)) {
                        continue;
                    }

                    $entryDate = new \DateTimeImmutable($entry['date']);
                    if ($entryDate < $from || $entryDate > $to) {
                        continue;
                    }

                    $amount = (float) ($entry['amount'] ?? 0);
                    if ($amount <= 0) {
                        continue;
                    }

                    match ($refType) {
                        'bounty_prizes' => $bountyTotal += $amount,
                        'ess_escrow_transfer' => $essTotal += $amount,
                        'agent_mission_reward', 'agent_mission_time_bonus_reward' => $missionTotal += $amount,
                        default => null,
                    };
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return new JsonResponse([
            'period' => [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'days' => $days,
            ],
            'income' => [
                'bounty' => $bountyTotal,
                'ess' => $essTotal,
                'mission' => $missionTotal,
                ...$incomeByType,
            ],
            'expenses' => $expensesByType,
        ]);
    }
}
