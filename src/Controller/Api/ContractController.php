<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\CharacterRepository;
use App\Service\ESI\EsiClient;
use App\Service\StructureMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/contracts')]
class ContractController extends AbstractController
{
    private const FORGE_REGION_ID = 10000002; // The Forge (Jita)
    private const CJ6MT_KEEPSTAR_ID = 1049588174021; // C-J6MT - 1st Taj Mahgoon (Keepstar)

    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
        private readonly EsiClient $esiClient,
        private readonly StructureMarketService $structureMarketService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'api_contracts_list', methods: ['GET'])]
    public function listContracts(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        $token = $mainCharacter->getEveToken();
        if ($token === null) {
            return new JsonResponse(['error' => 'No token available'], Response::HTTP_FORBIDDEN);
        }

        $statusFilter = $request->query->get('status', 'outstanding');

        try {
            $characterId = $mainCharacter->getEveCharacterId();

            // Get user's contracts
            $userContracts = $this->esiClient->getPaginated(
                "/characters/{$characterId}/contracts/",
                $token
            );

            // Filter by status if specified
            if ($statusFilter !== 'all') {
                $userContracts = array_filter($userContracts, fn($c) => ($c['status'] ?? '') === $statusFilter);
            }

            // Only keep item_exchange contracts for price comparison
            $itemExchangeContracts = array_filter($userContracts, fn($c) => $c['type'] === 'item_exchange');

            // Get public contracts in the region for comparison
            $publicContracts = $this->getPublicContractsForComparison();

            // Fetch items for each contract and calculate price comparison
            $result = [];
            foreach ($itemExchangeContracts as $contract) {
                $contractData = $this->processContract($contract, $characterId, $token, $publicContracts);
                if ($contractData !== null) {
                    $result[] = $contractData;
                }
            }

            // Sort by date_issued descending
            usort($result, fn($a, $b) => strtotime($b['dateIssued']) - strtotime($a['dateIssued']));

            return new JsonResponse([
                'contracts' => $result,
                'total' => count($result),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch contracts', [
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'Failed to fetch contracts: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get public contracts in The Forge region for price comparison.
     * Returns a map of "typeId-quantity" => array of prices
     */
    private function getPublicContractsForComparison(): array
    {
        try {
            // Get public contracts in The Forge (Jita region)
            $publicContracts = $this->esiClient->get(
                "/contracts/public/" . self::FORGE_REGION_ID . "/"
            );

            // Index by type_id for quick lookup
            // We'll store contract prices grouped by their items
            $contractPrices = [];

            foreach ($publicContracts as $contract) {
                if ($contract['type'] !== 'item_exchange') {
                    continue;
                }
                if (($contract['status'] ?? '') !== 'outstanding') {
                    continue;
                }

                // Store contract info for later matching
                $contractPrices[$contract['contract_id']] = [
                    'price' => $contract['price'] ?? 0,
                    'volume' => $contract['volume'] ?? 0,
                ];
            }

            return $contractPrices;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch public contracts', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    #[Route('/{contractId}/items', name: 'api_contract_items', methods: ['GET'])]
    public function getContractItems(int $contractId): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            return new JsonResponse(['error' => 'No main character set'], Response::HTTP_FORBIDDEN);
        }

        $token = $mainCharacter->getEveToken();
        if ($token === null) {
            return new JsonResponse(['error' => 'No token available'], Response::HTTP_FORBIDDEN);
        }

        try {
            $characterId = $mainCharacter->getEveCharacterId();

            // Get the contract items
            $items = $this->esiClient->get(
                "/characters/{$characterId}/contracts/{$contractId}/items/",
                $token
            );

            // Resolve type names
            $typeIds = array_unique(array_column($items, 'type_id'));
            $typeNames = $this->resolveTypeNames($typeIds);

            // Return items with Jita prices
            $result = [];
            foreach ($items as $item) {
                $typeId = $item['type_id'];
                $quantity = $item['quantity'];
                $jitaPrice = $this->getLowestSellPrice($typeId);

                $result[] = [
                    'typeId' => $typeId,
                    'typeName' => $typeNames[$typeId] ?? 'Unknown',
                    'quantity' => $quantity,
                    'isIncluded' => $item['is_included'] ?? true,
                    'isSingleton' => $item['is_singleton'] ?? false,
                    'jitaPrice' => $jitaPrice,
                    'jitaValue' => $jitaPrice !== null ? $jitaPrice * $quantity : null,
                ];
            }

            return new JsonResponse(['items' => $result]);
        } catch (\Throwable $e) {
            return new JsonResponse([
                'error' => 'Failed to fetch contract items: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function processContract(array $contract, int $characterId, $token, array $publicContracts): ?array
    {
        try {
            // Fetch contract items
            $items = $this->esiClient->get(
                "/characters/{$characterId}/contracts/{$contract['contract_id']}/items/",
                $token
            );

            // Only include items that are being sold (is_included = true)
            $includedItems = array_filter($items, fn($item) => ($item['is_included'] ?? true));

            if (empty($includedItems)) {
                return null;
            }

            // Resolve type names
            $typeIds = array_unique(array_column($includedItems, 'type_id'));
            $typeNames = $this->resolveTypeNames($typeIds);

            // Build items list with Jita and Delve prices
            $itemsWithPrices = [];
            $jitaValue = 0;
            $delveValue = 0;

            foreach ($includedItems as $item) {
                $typeId = $item['type_id'];
                $quantity = $item['quantity'];
                $typeName = $typeNames[$typeId] ?? 'Unknown';

                // Get Jita market price (regional)
                $jitaPrice = $this->getLowestSellPrice($typeId, self::FORGE_REGION_ID);
                $itemJitaValue = $jitaPrice !== null ? $jitaPrice * $quantity : null;

                // Get C-J6MT Keepstar market price from cache (synced hourly in background)
                $delvePrice = $this->structureMarketService->getLowestSellPrice(self::CJ6MT_KEEPSTAR_ID, $typeId);
                $itemDelveValue = $delvePrice !== null ? $delvePrice * $quantity : null;

                if ($itemJitaValue !== null) {
                    $jitaValue += $itemJitaValue;
                }
                if ($itemDelveValue !== null) {
                    $delveValue += $itemDelveValue;
                }

                $itemsWithPrices[] = [
                    'typeId' => $typeId,
                    'typeName' => $typeName,
                    'quantity' => $quantity,
                    'jitaPrice' => $jitaPrice,
                    'jitaValue' => $itemJitaValue,
                    'delvePrice' => $delvePrice,
                    'delveValue' => $itemDelveValue,
                ];
            }

            $contractPrice = $contract['price'] ?? 0;
            $contractVolume = $contract['volume'] ?? 0;

            // Find similar contracts by volume and compare prices
            $similarContracts = $this->findSimilarPublicContracts($contractVolume, $publicContracts);

            // Calculate comparison metrics for similar contracts
            $lowestSimilar = null;
            $avgSimilar = null;
            $similarCount = count($similarContracts);

            if ($similarCount > 0) {
                $prices = array_column($similarContracts, 'price');
                $lowestSimilar = min($prices);
                $avgSimilar = array_sum($prices) / $similarCount;
            }

            // Determine if user is seller or buyer
            $isSeller = $contract['issuer_id'] === $characterId;

            // Price diff vs Jita
            $jitaDiff = $jitaValue > 0 ? $contractPrice - $jitaValue : null;
            $jitaDiffPercent = $jitaValue > 0 ? (($contractPrice - $jitaValue) / $jitaValue) * 100 : null;

            // Price diff vs Delve
            $delveDiff = $delveValue > 0 ? $contractPrice - $delveValue : null;
            $delveDiffPercent = $delveValue > 0 ? (($contractPrice - $delveValue) / $delveValue) * 100 : null;

            // Price diff vs similar contracts
            $similarDiff = $lowestSimilar !== null ? $contractPrice - $lowestSimilar : null;
            $similarDiffPercent = $lowestSimilar !== null && $lowestSimilar > 0
                ? (($contractPrice - $lowestSimilar) / $lowestSimilar) * 100
                : null;

            // Competitive thresholds:
            // - Sellers: price should be at or below the lowest similar (within 5%), or within 10% of Jita
            // - Buyers: paying less than market is GOOD (0 ISK = best deal), paying up to 5% more is acceptable
            $isCompetitive = null;
            if ($similarDiffPercent !== null) {
                if ($isSeller) {
                    $isCompetitive = $similarDiffPercent <= 5; // max 5% above lowest similar
                } else {
                    $isCompetitive = $similarDiffPercent <= 5; // paying at or below lowest similar + 5% is good
                }
            } elseif ($jitaDiffPercent !== null) {
                // Fallback to Jita comparison if no similar contracts
                if ($isSeller) {
                    $isCompetitive = $jitaDiffPercent <= 10; // max 10% markup over Jita
                } else {
                    $isCompetitive = $jitaDiffPercent <= 10; // paying at or below Jita + 10% is good
                }
            }

            return [
                'contractId' => $contract['contract_id'],
                'type' => $contract['type'],
                'status' => $contract['status'] ?? 'unknown',
                'title' => $contract['title'] ?? '',
                'price' => $contractPrice,
                'reward' => $contract['reward'] ?? 0,
                'volume' => $contractVolume,
                'dateIssued' => $contract['date_issued'],
                'dateExpired' => $contract['date_expired'] ?? null,
                'dateCompleted' => $contract['date_completed'] ?? null,
                'issuerId' => $contract['issuer_id'],
                'assigneeId' => $contract['assignee_id'] ?? null,
                'acceptorId' => $contract['acceptor_id'] ?? 0,
                'forCorporation' => $contract['for_corporation'] ?? false,
                'isSeller' => $isSeller,
                'items' => $itemsWithPrices,
                'itemCount' => count($itemsWithPrices),
                // Jita comparison
                'jitaValue' => $jitaValue > 0 ? $jitaValue : null,
                'jitaDiff' => $jitaDiff,
                'jitaDiffPercent' => $jitaDiffPercent,
                // Delve comparison (C-J6MT)
                'delveValue' => $delveValue > 0 ? $delveValue : null,
                'delveDiff' => $delveDiff,
                'delveDiffPercent' => $delveDiffPercent,
                // Similar contracts comparison
                'similarCount' => $similarCount,
                'lowestSimilar' => $lowestSimilar,
                'avgSimilar' => $avgSimilar,
                'similarDiff' => $similarDiff,
                'similarDiffPercent' => $similarDiffPercent,
                // Overall assessment
                'isCompetitive' => $isCompetitive,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to process contract', [
                'contractId' => $contract['contract_id'],
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function getLowestSellPrice(int $typeId, int $regionId = self::FORGE_REGION_ID): ?float
    {
        try {
            $orders = $this->esiClient->get(
                "/markets/{$regionId}/orders/?type_id={$typeId}&order_type=sell"
            );

            if (empty($orders)) {
                return null;
            }

            return min(array_column($orders, 'price'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Find public contracts with similar volume (proxy for similar content).
     * Uses volume matching since we can't efficiently fetch all public contract items.
     */
    private function findSimilarPublicContracts(float $volume, array $publicContracts): array
    {
        if ($volume <= 0) {
            return [];
        }

        // Find contracts with volume within 2% of target (tight match for identical items)
        $tolerance = 0.02;
        $minVolume = $volume * (1 - $tolerance);
        $maxVolume = $volume * (1 + $tolerance);

        $similar = [];
        foreach ($publicContracts as $contractId => $contract) {
            $contractVolume = $contract['volume'] ?? 0;
            if ($contractVolume >= $minVolume && $contractVolume <= $maxVolume) {
                $similar[] = $contract;
            }
        }

        return $similar;
    }

    private function resolveTypeNames(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        try {
            $response = $this->esiClient->post('/universe/names/', array_values($typeIds));
            $names = [];
            foreach ($response as $item) {
                $names[$item['id']] = $item['name'];
            }
            return $names;
        } catch (\Throwable) {
            return [];
        }
    }
}
