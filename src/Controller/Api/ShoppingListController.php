<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\ESI\EsiClient;
use App\Service\ESI\MarketService;
use App\Service\StructureMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/shopping-list')]
class ShoppingListController extends AbstractController
{
    private const DEFAULT_STRUCTURE_ID = 1049588174021;
    private const DEFAULT_STRUCTURE_NAME = 'C-J6MT - 1st Taj Mahgoon (Keepstar)';

    public function __construct(
        private readonly Security $security,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly MarketService $marketService,
        private readonly EsiClient $esiClient,
        private readonly StructureMarketService $structureMarketService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Parse a pasted list of items and return priced results.
     *
     * Supported formats:
     * - "Item Name\t123" (tab-separated, EVE clipboard)
     * - "Item Name  123" (space-separated)
     * - "123x Item Name" (quantity prefix)
     * - "123 x Item Name" (quantity with x separator)
     * - "Item Name x 123" (quantity suffix)
     */
    #[Route('/parse', name: 'api_shopping_list_parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $data = json_decode($request->getContent(), true);
        $text = $data['text'] ?? '';
        $structureId = isset($data['structureId']) ? (int) $data['structureId'] : $user->getPreferredMarketStructureId();
        $transportCostPerM3 = (float) ($data['transportCost'] ?? 1200);

        if (empty(trim($text))) {
            return new JsonResponse(['error' => 'No text provided'], Response::HTTP_BAD_REQUEST);
        }

        // Parse the text into items
        $parsedItems = $this->parseItemList($text);

        if (empty($parsedItems)) {
            return new JsonResponse(['error' => 'No items could be parsed from the text'], Response::HTTP_BAD_REQUEST);
        }

        // Resolve item names to type IDs
        $resolvedItems = $this->resolveItemNames($parsedItems);

        if (empty($resolvedItems['found'])) {
            return new JsonResponse([
                'items' => [],
                'notFound' => $resolvedItems['notFound'],
                'totals' => $this->emptyTotals(),
            ]);
        }

        // Get token for market access
        $token = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null) {
                $token = $character->getEveToken();
                break;
            }
        }

        // Get prices and enrich items
        $typeIds = array_column($resolvedItems['found'], 'typeId');

        // Get volumes
        $volumes = [];
        foreach ($this->invTypeRepository->findBy(['typeId' => $typeIds]) as $type) {
            $volumes[$type->getTypeId()] = $type->getVolume() ?? 0.0;
        }

        // Fetch prices
        $priceData = null;
        $priceError = null;
        try {
            $priceData = $this->marketService->comparePrices($typeIds, $structureId, $token);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch market prices for shopping list', [
                'error' => $e->getMessage(),
            ]);
            $priceError = 'Unable to fetch market prices. Please try again later.';
        }

        // Calculate prices and totals
        $items = [];
        $totalJita = 0.0;
        $totalImport = 0.0;
        $totalJitaWithImport = 0.0;
        $totalStructure = 0.0;
        $totalBest = 0.0;
        $totalVolume = 0.0;

        foreach ($resolvedItems['found'] as $item) {
            $typeId = $item['typeId'];
            $quantity = $item['quantity'];
            $volume = $volumes[$typeId] ?? 0.0;
            $totalItemVolume = $volume * $quantity;

            $jitaPrice = $priceData !== null ? ($priceData['jita'][$typeId] ?? null) : null;
            $structurePrice = $priceData !== null ? ($priceData['structure'][$typeId] ?? null) : null;

            $jitaTotal = $jitaPrice !== null ? $jitaPrice * $quantity : null;
            $structureTotal = $structurePrice !== null ? $structurePrice * $quantity : null;

            // Calculate import cost from Jita
            $importCost = $totalItemVolume * $transportCostPerM3;
            $jitaWithImport = $jitaTotal !== null ? $jitaTotal + $importCost : null;

            // Determine best price
            $bestLocation = null;
            $bestTotal = null;
            if ($jitaWithImport !== null && $structureTotal !== null) {
                if ($jitaWithImport <= $structureTotal) {
                    $bestLocation = 'jita';
                    $bestTotal = $jitaWithImport;
                } else {
                    $bestLocation = 'structure';
                    $bestTotal = $structureTotal;
                }
            } elseif ($jitaWithImport !== null) {
                $bestLocation = 'jita';
                $bestTotal = $jitaWithImport;
            } elseif ($structureTotal !== null) {
                $bestLocation = 'structure';
                $bestTotal = $structureTotal;
            }

            $items[] = [
                'typeId' => $typeId,
                'typeName' => $item['typeName'],
                'quantity' => $quantity,
                'volume' => $volume,
                'totalVolume' => round($totalItemVolume, 2),
                'jitaPrice' => $jitaPrice,
                'jitaTotal' => $jitaTotal,
                'importCost' => round($importCost, 2),
                'jitaWithImport' => $jitaWithImport !== null ? round($jitaWithImport, 2) : null,
                'structurePrice' => $structurePrice,
                'structureTotal' => $structureTotal,
                'bestLocation' => $bestLocation,
                'bestTotal' => $bestTotal !== null ? round($bestTotal, 2) : null,
            ];

            // Accumulate totals
            if ($jitaTotal !== null) {
                $totalJita += $jitaTotal;
            }
            $totalImport += $importCost;
            if ($jitaWithImport !== null) {
                $totalJitaWithImport += $jitaWithImport;
            }
            if ($structureTotal !== null) {
                $totalStructure += $structureTotal;
            }
            if ($bestTotal !== null) {
                $totalBest += $bestTotal;
            }
            $totalVolume += $totalItemVolume;
        }

        // Format last sync time
        $structureLastSync = null;
        if ($priceData !== null && isset($priceData['structureLastSync']) && $priceData['structureLastSync'] instanceof \DateTimeImmutable) {
            $structureLastSync = $priceData['structureLastSync']->format('c');
        }

        return new JsonResponse([
            'items' => $items,
            'notFound' => $resolvedItems['notFound'],
            'totals' => [
                'jita' => round($totalJita, 2),
                'import' => round($totalImport, 2),
                'jitaWithImport' => round($totalJitaWithImport, 2),
                'structure' => round($totalStructure, 2),
                'best' => round($totalBest, 2),
                'volume' => round($totalVolume, 2),
            ],
            'transportCostPerM3' => $transportCostPerM3,
            'structureId' => $structureId,
            'structureName' => $priceData['structureName'] ?? null,
            'structureAccessible' => $priceData !== null && ($priceData['structureAccessible'] ?? false),
            'structureFromCache' => $priceData !== null && ($priceData['structureFromCache'] ?? false),
            'structureLastSync' => $structureLastSync,
            'priceError' => $priceError,
        ]);
    }

    /**
     * Parse raw text into item name/quantity pairs.
     *
     * @return array<array{name: string, quantity: int}>
     */
    private function parseItemList(string $text): array
    {
        $items = [];
        $lines = preg_split('/\r?\n/', trim($text));

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parsed = $this->parseLine($line);
            if ($parsed !== null) {
                // Consolidate duplicates
                $found = false;
                foreach ($items as &$item) {
                    if (strcasecmp($item['name'], $parsed['name']) === 0) {
                        $item['quantity'] += $parsed['quantity'];
                        $found = true;
                        break;
                    }
                }
                unset($item);

                if (!$found) {
                    $items[] = $parsed;
                }
            }
        }

        return $items;
    }

    /**
     * Parse a single line into item name and quantity.
     *
     * @return array{name: string, quantity: int}|null
     */
    private function parseLine(string $line): ?array
    {
        // Remove common prefixes/suffixes
        $line = preg_replace('/^\s*[-*•]\s*/', '', $line); // bullet points

        // Format: "123x Item Name" or "123 x Item Name"
        if (preg_match('/^(\d[\d,\s]*)\s*x\s+(.+)$/i', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[1]);
            return ['name' => trim($matches[2]), 'quantity' => max(1, $quantity)];
        }

        // Format: "Item Name x 123" or "Item Name x123"
        if (preg_match('/^(.+?)\s+x\s*(\d[\d,\s]*)$/i', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);
            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        // Format: Tab-separated "Item Name\t123" (EVE clipboard)
        if (preg_match('/^(.+?)\t+(\d[\d,\s]*)$/', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);
            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        // Format: "Item Name  123" (multiple spaces before number at end)
        if (preg_match('/^(.+?)\s{2,}(\d[\d,\s]*)$/', $line, $matches)) {
            $quantity = (int) str_replace([',', ' '], '', $matches[2]);
            return ['name' => trim($matches[1]), 'quantity' => max(1, $quantity)];
        }

        // Format: "Item Name 123" (single space, number at very end)
        if (preg_match('/^(.+?)\s+(\d[\d,]*)$/', $line, $matches)) {
            $name = trim($matches[1]);
            // Avoid parsing things like "Capital Ship" where "Ship" is not a number
            if (!preg_match('/^\d/', $name)) {
                $quantity = (int) str_replace([',', ' '], '', $matches[2]);
                return ['name' => $name, 'quantity' => max(1, $quantity)];
            }
        }

        // Format: Just an item name (quantity = 1)
        if (preg_match('/^[a-zA-Z]/', $line)) {
            return ['name' => $line, 'quantity' => 1];
        }

        return null;
    }

    /**
     * Resolve item names to type IDs.
     *
     * @param array<array{name: string, quantity: int}> $items
     * @return array{found: array<array{typeId: int, typeName: string, quantity: int}>, notFound: string[]}
     */
    private function resolveItemNames(array $items): array
    {
        $found = [];
        $notFound = [];

        foreach ($items as $item) {
            $name = $item['name'];
            $quantity = $item['quantity'];

            // Try exact match first
            $type = $this->invTypeRepository->findOneBy(['typeName' => $name]);

            // Try case-insensitive if exact fails
            if ($type === null) {
                $types = $this->invTypeRepository->createQueryBuilder('t')
                    ->where('LOWER(t.typeName) = LOWER(:name)')
                    ->setParameter('name', $name)
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getResult();

                $type = $types[0] ?? null;
            }

            if ($type !== null && $type->isPublished()) {
                $found[] = [
                    'typeId' => $type->getTypeId(),
                    'typeName' => $type->getTypeName(),
                    'quantity' => $quantity,
                ];
            } else {
                $notFound[] = $name;
            }
        }

        return ['found' => $found, 'notFound' => $notFound];
    }

    /**
     * @return array<string, float>
     */
    private function emptyTotals(): array
    {
        return [
            'jita' => 0.0,
            'import' => 0.0,
            'jitaWithImport' => 0.0,
            'structure' => 0.0,
            'best' => 0.0,
            'volume' => 0.0,
        ];
    }

    /**
     * Search for structures by name using ESI.
     * Returns structures the user has docking access to.
     */
    #[Route('/search-structures', name: 'api_shopping_list_search_structures', methods: ['GET'])]
    public function searchStructures(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $query = trim($request->query->getString('q', ''));
        if (strlen($query) < 3) {
            return new JsonResponse(['results' => []]);
        }

        // Get a character with a valid token
        $token = null;
        $characterId = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null) {
                $token = $character->getEveToken();
                $characterId = $character->getEveCharacterId();
                break;
            }
        }

        if ($token === null || $characterId === null) {
            return new JsonResponse(['error' => 'No character with valid token'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Search for structures
            $searchResult = $this->esiClient->get(
                "/characters/{$characterId}/search/?categories=structure&search=" . urlencode($query),
                $token
            );

            $structureIds = $searchResult['structure'] ?? [];

            // Limit to first 10 results
            $structureIds = array_slice($structureIds, 0, 10);

            $results = [];
            foreach ($structureIds as $structureId) {
                try {
                    $info = $this->esiClient->get("/universe/structures/{$structureId}/", $token);
                    $results[] = [
                        'id' => $structureId,
                        'name' => $info['name'] ?? 'Unknown',
                        'typeId' => $info['type_id'] ?? null,
                        'solarSystemId' => $info['solar_system_id'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    // Skip structures we can't access
                    $this->logger->debug('Could not fetch structure info', [
                        'structureId' => $structureId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return new JsonResponse(['results' => $results]);
        } catch (\Throwable $e) {
            $this->logger->warning('Structure search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Search failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sync structure market data (manual trigger).
     */
    #[Route('/sync-structure-market', name: 'api_shopping_list_sync_structure_market', methods: ['POST'])]
    public function syncStructureMarket(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->security->getUser();

        $data = json_decode($request->getContent(), true);
        $structureId = isset($data['structureId']) ? (int) $data['structureId'] : self::DEFAULT_STRUCTURE_ID;

        // Get a character with a valid token
        $token = null;
        foreach ($user->getCharacters() as $character) {
            if ($character->getEveToken() !== null) {
                $token = $character->getEveToken();
                break;
            }
        }

        if ($token === null) {
            return new JsonResponse(['error' => 'No character with valid token'], Response::HTTP_BAD_REQUEST);
        }

        // Get structure name
        $structureName = $this->marketService->getStructureName($structureId, $token);
        if ($structureName === null) {
            $structureName = $structureId === self::DEFAULT_STRUCTURE_ID
                ? self::DEFAULT_STRUCTURE_NAME
                : "Structure {$structureId}";
        }

        try {
            // Clear old cache first (may contain old format with all prices)
            $this->structureMarketService->clearCache($structureId);

            $result = $this->structureMarketService->syncStructureMarket($structureId, $structureName, $token);

            if (!$result['success']) {
                return new JsonResponse([
                    'error' => $result['error'] ?? 'Sync failed',
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return new JsonResponse([
                'success' => true,
                'structureId' => $structureId,
                'structureName' => $structureName,
                'totalOrders' => $result['totalOrders'],
                'sellOrders' => $result['sellOrders'],
                'typeCount' => $result['typeCount'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Structure market sync failed', [
                'structureId' => $structureId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'Sync failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
