<?php

declare(strict_types=1);

namespace App\State\Processor\Pve;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Pve\DetectedSaleResource;
use App\ApiResource\Pve\ScanLootSalesResultResource;
use App\Entity\PveIncome;
use App\Entity\User;
use App\Repository\PveIncomeRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Repository\UserPveSettingsRepository;
use App\Entity\EveToken;
use App\Service\ESI\EsiClient;
use App\Service\ESI\TokenManager;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @implements ProcessorInterface<mixed, ScanLootSalesResultResource>
 */
class ScanLootSalesProcessor implements ProcessorInterface
{
    private const CACHE_TTL_PROJECTS = 900; // 15 minutes
    private const CACHE_TTL_CONTRIBUTORS = 300; // 5 minutes

    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly TokenManager $tokenManager,
        private readonly PveIncomeRepository $incomeRepository,
        private readonly UserPveSettingsRepository $settingsRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ScanLootSalesResultResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', 30) ?? 30);
        $from = new \DateTimeImmutable("-{$days} days");

        $settings = $this->settingsRepository->getOrCreate($user);
        $lootTypeIds = $settings->getLootTypeIds();

        $resource = new ScanLootSalesResultResource();

        if (empty($lootTypeIds)) {
            $resource->scannedTransactions = 0;
            $resource->scannedContracts = 0;
            $resource->detectedSales = [];
            $resource->noLootTypesConfigured = true;

            return $resource;
        }

        $importedTransactionIds = $this->incomeRepository->getImportedTransactionIds($user);
        $declinedTransactionIds = $settings->getDeclinedLootSaleTransactionIds();

        $detectedSales = [];
        $scannedTransactions = 0;
        $scannedContracts = 0;

        // Scan market transactions
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
                    $scannedTransactions++;

                    if (in_array($transaction['transaction_id'], $importedTransactionIds, true)) {
                        continue;
                    }
                    if (in_array($transaction['transaction_id'], $declinedTransactionIds, true)) {
                        continue;
                    }

                    if ($transaction['is_buy'] ?? true) {
                        continue;
                    }

                    $transactionDate = new \DateTimeImmutable($transaction['date']);
                    if ($transactionDate < $from) {
                        continue;
                    }

                    $typeId = $transaction['type_id'];
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

                    $sale = new DetectedSaleResource();
                    $sale->transactionId = $transaction['transaction_id'];
                    $sale->contractId = 0;
                    $sale->type = PveIncome::TYPE_LOOT_SALE;
                    $sale->typeId = $typeId;
                    $sale->typeName = "{$quantity}x {$typeName}";
                    $sale->quantity = $quantity;
                    $sale->price = $totalPrice;
                    $sale->dateIssued = $transactionDate->format('Y-m-d');
                    $sale->characterName = $character->getName();
                    $sale->source = 'market';

                    $detectedSales[] = $sale;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Scan contracts for loot sales
        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                $contracts = $this->esiClient->getPaginated(
                    "/characters/{$character->getEveCharacterId()}/contracts/",
                    $token
                );

                foreach ($contracts as $contract) {
                    $scannedContracts++;

                    if ($contract['type'] !== 'item_exchange') {
                        continue;
                    }
                    if (!in_array($contract['status'] ?? '', ['finished', 'completed'], true)) {
                        continue;
                    }

                    $isAcceptor = ($contract['acceptor_id'] ?? 0) === $character->getEveCharacterId();
                    $isIssuer = ($contract['issuer_id'] ?? 0) === $character->getEveCharacterId();

                    if (!$isAcceptor && !$isIssuer) {
                        continue;
                    }

                    $contractDate = new \DateTimeImmutable($contract['date_completed'] ?? $contract['date_accepted'] ?? $contract['date_issued']);
                    if ($contractDate < $from) {
                        continue;
                    }

                    $price = (float) ($contract['price'] ?? 0);
                    $reward = (float) ($contract['reward'] ?? 0);

                    $iskReceived = 0;
                    if ($isIssuer && $price > 0) {
                        $iskReceived = $price;
                    } elseif ($isAcceptor && $reward > 0) {
                        $iskReceived = $reward;
                    }

                    if ($iskReceived <= 0) {
                        continue;
                    }

                    $trackingId = -(int) $contract['contract_id'];

                    if (in_array($trackingId, $importedTransactionIds, true)) {
                        continue;
                    }
                    if (in_array($trackingId, $declinedTransactionIds, true)) {
                        continue;
                    }

                    try {
                        $items = $this->esiClient->get(
                            "/characters/{$character->getEveCharacterId()}/contracts/{$contract['contract_id']}/items/",
                            $token
                        );
                    } catch (\Throwable) {
                        continue;
                    }

                    $soldLootItems = [];

                    foreach ($items as $item) {
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

                    $description = implode(', ', array_map(
                        fn($i) => "{$i['quantity']}x {$i['typeName']}",
                        $soldLootItems
                    ));

                    $firstItem = $soldLootItems[0];

                    $sale = new DetectedSaleResource();
                    $sale->transactionId = $trackingId;
                    $sale->contractId = $contract['contract_id'];
                    $sale->type = PveIncome::TYPE_LOOT_SALE;
                    $sale->typeId = $firstItem['typeId'];
                    $sale->typeName = $description;
                    $sale->quantity = array_sum(array_column($soldLootItems, 'quantity'));
                    $sale->price = $iskReceived;
                    $sale->dateIssued = $contractDate->format('Y-m-d');
                    $sale->characterName = $character->getName();
                    $sale->source = 'contract';

                    $detectedSales[] = $sale;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Scan corporation projects contributions
        $scannedProjects = 0;
        $scannedCorporations = [];
        $compatHeaders = ['X-Compatibility-Date' => '2025-12-16'];

        foreach ($user->getCharacters() as $character) {
            $token = $character->getEveToken();
            if ($token === null) {
                continue;
            }

            $corporationId = $character->getCorporationId();

            // Skip NPC corporations (ID < 2000000)
            if ($corporationId < 2000000) {
                continue;
            }

            // Skip if we already scanned this corporation
            if (in_array($corporationId, $scannedCorporations, true)) {
                continue;
            }
            $scannedCorporations[] = $corporationId;

            try {
                if ($token->isExpiringSoon()) {
                    $this->tokenManager->refreshAccessToken($token);
                }

                // Get corporation projects (cached)
                $projects = $this->getCachedCorpProjects($corporationId, $token, $compatHeaders);

                $this->logger->info('Corp projects: Found ' . count($projects) . ' projects for corp ' . $corporationId);

                // Check active projects AND recently completed projects (last 7 days)
                $oneWeekAgo = new \DateTimeImmutable('-7 days');
                $relevantProjects = array_filter($projects, function($p) use ($oneWeekAgo) {
                    $state = $p['state'] ?? '';
                    if ($state === 'Active') {
                        return true;
                    }
                    if ($state === 'Completed') {
                        $completedAt = isset($p['completed_at']) ? new \DateTimeImmutable($p['completed_at']) : null;
                        return $completedAt !== null && $completedAt >= $oneWeekAgo;
                    }
                    return false;
                });

                // Build list of user's character IDs in this corporation
                $userCharacterIds = [];
                $userCharactersMap = [];
                foreach ($user->getCharacters() as $char) {
                    if ($char->getCorporationId() === $corporationId && $char->getEveToken() !== null) {
                        $userCharacterIds[] = $char->getEveCharacterId();
                        $userCharactersMap[$char->getEveCharacterId()] = $char;
                    }
                }

                foreach ($relevantProjects as $project) {
                    $scannedProjects++;
                    $projectId = $project['id'] ?? null;
                    if ($projectId === null) {
                        continue;
                    }
                    $projectName = $project['name'] ?? 'Project';

                    // First get list of contributors (cached)
                    try {
                        $contributorIds = $this->getCachedContributors($corporationId, $projectId, $token, $compatHeaders);

                        // Check if any of user's characters contributed
                        $matchingCharIds = array_intersect($userCharacterIds, $contributorIds);

                        if (empty($matchingCharIds)) {
                            continue; // No contributions from user's characters
                        }

                        $this->logger->info("Corp projects: Found " . count($matchingCharIds) . " contributors in project {$projectName}");

                        // Get project details (cached) to find type_id and reward per unit
                        $projectDetails = $this->getCachedProjectDetails($corporationId, $projectId, $token, $compatHeaders);

                        // Get the type_id from configuration.deliver_item.items
                        $deliverItems = $projectDetails['configuration']['deliver_item']['items'] ?? [];
                        $typeId = $deliverItems[0]['type_id'] ?? 0;

                        // Get reward per unit from contribution.reward_per_contribution
                        $rewardPerUnit = (float) ($projectDetails['contribution']['reward_per_contribution'] ?? 0);

                        // Fetch detailed contributions only for matching characters
                        foreach ($matchingCharIds as $characterId) {
                            $charToCheck = $userCharactersMap[$characterId];
                            $charToken = $charToCheck->getEveToken();
                            if ($charToken === null) {
                                continue;
                            }

                            try {
                                $contributionUrl = "https://esi.evetech.net/corporations/{$corporationId}/projects/{$projectId}/contribution/{$characterId}";
                                $contribution = $this->fetchFromEsi($contributionUrl, $charToken, $compatHeaders);

                                $this->logger->info('Corp projects: Contribution for ' . $charToCheck->getName() . ': ' . json_encode($contribution));

                                // The contribution response has: contributed (quantity), last_modified (date)
                                $quantity = $contribution['contributed'] ?? $contribution['quantity'] ?? $contribution['amount'] ?? 0;
                                $contributedAt = $contribution['last_modified'] ?? $contribution['contributed_at'] ?? null;

                                if ($quantity <= 0) {
                                    continue;
                                }

                                // Check date if available
                                if ($contributedAt !== null) {
                                    try {
                                        $contributionDate = new \DateTimeImmutable($contributedAt);
                                        if ($contributionDate < $from) {
                                            continue;
                                        }
                                    } catch (\Exception) {
                                        // Invalid date, skip date check
                                    }
                                }

                                // Create tracking ID
                                $trackingId = -abs(crc32($projectId . '_' . $characterId));

                                if (in_array($trackingId, $importedTransactionIds, true)) {
                                    continue;
                                }
                                if (in_array($trackingId, $declinedTransactionIds, true)) {
                                    continue;
                                }

                                $typeName = $typeId > 0
                                    ? ($this->invTypeRepository->find($typeId)?->getTypeName() ?? "Type #{$typeId}")
                                    : 'Items';

                                // Calculate price based on quantity contributed * reward per unit
                                $price = $quantity * $rewardPerUnit;

                                $sale = new DetectedSaleResource();
                                $sale->transactionId = $trackingId;
                                $sale->contractId = 0;
                                $sale->projectId = $projectId;
                                $sale->type = PveIncome::TYPE_CORP_PROJECT;
                                $sale->typeId = $typeId;
                                $sale->typeName = "{$quantity}x {$typeName}";
                                $sale->quantity = $quantity;
                                $sale->price = $price;
                                $sale->dateIssued = $contributedAt ? (new \DateTimeImmutable($contributedAt))->format('Y-m-d') : date('Y-m-d');
                                $sale->characterName = $charToCheck->getName();
                                $sale->source = 'corp_project';
                                $sale->projectName = $projectName;

                                $detectedSales[] = $sale;
                            } catch (\Throwable) {
                                continue;
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->logger->debug("Corp projects: Could not get contributors for {$projectName}: {$e->getMessage()}");
                        continue;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning("Corp projects: Error for corp {$corporationId}: {$e->getMessage()}");
                continue;
            }
        }

        $resource->scannedTransactions = $scannedTransactions;
        $resource->scannedContracts = $scannedContracts;
        $resource->scannedProjects = $scannedProjects;
        $resource->detectedSales = $detectedSales;
        $resource->noLootTypesConfigured = false;

        return $resource;
    }

    /**
     * Fetch from a full ESI URL (bypasses EsiClient base URL for Data Hub endpoints).
     *
     * @param array<string, string> $headers
     * @return array<mixed>
     */
    private function fetchFromEsi(string $fullUrl, EveToken $token, array $headers = [], int $timeout = 10): array
    {
        $accessToken = $this->tokenManager->getValidAccessToken($token);

        $requestHeaders = [
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$accessToken}",
            ...$headers,
        ];

        $response = $this->httpClient->request('GET', $fullUrl, [
            'headers' => $requestHeaders,
            'timeout' => $timeout,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 200 && $statusCode < 300) {
            return $response->toArray();
        }

        throw new \RuntimeException("ESI request failed with status {$statusCode}");
    }

    /**
     * Get corporation projects with caching.
     *
     * @param array<string, string> $headers
     * @return array<mixed>
     */
    private function getCachedCorpProjects(int $corporationId, EveToken $token, array $headers): array
    {
        $cacheKey = "corp_projects_{$corporationId}";
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $this->logger->debug("Corp projects: Cache hit for projects list (corp {$corporationId})");
            return $cacheItem->get();
        }

        $projectsUrl = "https://esi.evetech.net/corporations/{$corporationId}/projects";
        $response = $this->fetchFromEsi($projectsUrl, $token, $headers);
        $projects = $response['projects'] ?? [];

        $cacheItem->set($projects);
        $cacheItem->expiresAfter(self::CACHE_TTL_PROJECTS);
        $this->cache->save($cacheItem);

        return $projects;
    }

    /**
     * Get project contributors with caching.
     *
     * @param array<string, string> $headers
     * @return array<int>
     */
    private function getCachedContributors(int $corporationId, string $projectId, EveToken $token, array $headers): array
    {
        $cacheKey = "corp_project_contributors_{$corporationId}_{$projectId}";
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $this->logger->debug("Corp projects: Cache hit for contributors (project {$projectId})");
            return $cacheItem->get();
        }

        $contributorsUrl = "https://esi.evetech.net/corporations/{$corporationId}/projects/{$projectId}/contributors";
        $contributorsResponse = $this->fetchFromEsi($contributorsUrl, $token, $headers);
        $contributors = $contributorsResponse['contributors'] ?? [];

        $contributorIds = array_map(fn($c) => $c['character_id'] ?? $c['id'] ?? 0, $contributors);

        $cacheItem->set($contributorIds);
        $cacheItem->expiresAfter(self::CACHE_TTL_CONTRIBUTORS);
        $this->cache->save($cacheItem);

        return $contributorIds;
    }

    /**
     * Get project details with caching.
     *
     * @param array<string, string> $headers
     * @return array<mixed>
     */
    private function getCachedProjectDetails(int $corporationId, string $projectId, EveToken $token, array $headers): array
    {
        $cacheKey = "corp_project_details_{$corporationId}_{$projectId}";
        $cacheItem = $this->cache->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            $this->logger->debug("Corp projects: Cache hit for project details (project {$projectId})");
            return $cacheItem->get();
        }

        $projectDetailsUrl = "https://esi.evetech.net/corporations/{$corporationId}/projects/{$projectId}";
        $projectDetails = $this->fetchFromEsi($projectDetailsUrl, $token, $headers);

        $cacheItem->set($projectDetails);
        $cacheItem->expiresAfter(self::CACHE_TTL_PROJECTS);
        $this->cache->save($cacheItem);

        return $projectDetails;
    }
}
