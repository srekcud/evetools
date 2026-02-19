<?php

declare(strict_types=1);

namespace App\State\Provider\Contract;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Contract\ContractItemResource;
use App\ApiResource\Contract\ContractListResource;
use App\ApiResource\Contract\ContractResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Service\ESI\EsiClient;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ContractListResource>
 */
class ContractCollectionProvider implements ProviderInterface
{
    private const FORGE_REGION_ID = EveConstants::THE_FORGE_REGION_ID;

    public function __construct(
        private readonly Security $security,
        private readonly EsiClient $esiClient,
        private readonly StructureMarketService $structureMarketService,
        private readonly JitaMarketService $jitaMarketService,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        private readonly int $defaultMarketStructureId,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ContractListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $token = $mainCharacter->getEveToken();
        if ($token === null) {
            throw new AccessDeniedHttpException('No token available');
        }

        $request = $this->requestStack->getCurrentRequest();
        $statusFilter = $request?->query->get('status', 'outstanding') ?? 'outstanding';

        try {
            $characterId = $mainCharacter->getEveCharacterId();

            $userContracts = $this->esiClient->getPaginated(
                "/characters/{$characterId}/contracts/",
                $token
            );

            if ($statusFilter !== 'all') {
                $userContracts = array_filter($userContracts, fn($c) => ($c['status'] ?? '') === $statusFilter);
            }

            $itemExchangeContracts = array_filter($userContracts, fn($c) => $c['type'] === 'item_exchange');

            $publicContracts = $this->getPublicContractsForComparison();

            // Pre-fetch all contract items to collect type IDs for batch price lookup
            $contractItems = [];
            $allTypeIds = [];

            foreach ($itemExchangeContracts as $contract) {
                try {
                    $items = $this->esiClient->get(
                        "/characters/{$characterId}/contracts/{$contract['contract_id']}/items/",
                        $token
                    );
                    $contractItems[$contract['contract_id']] = $items;

                    foreach ($items as $item) {
                        if ($item['is_included'] ?? true) {
                            $allTypeIds[] = $item['type_id'];
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger->warning('Failed to fetch contract items', [
                        'contractId' => $contract['contract_id'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Batch-fetch all Jita prices in a single call
            $allTypeIds = array_values(array_unique($allTypeIds));
            $jitaPrices = !empty($allTypeIds)
                ? $this->jitaMarketService->getPricesWithFallback($allTypeIds)
                : [];

            $result = [];
            foreach ($itemExchangeContracts as $contract) {
                $items = $contractItems[$contract['contract_id']] ?? null;
                if ($items === null) {
                    continue;
                }

                $contractData = $this->processContract($contract, $characterId, $items, $jitaPrices, $publicContracts);
                if ($contractData !== null) {
                    $result[] = $contractData;
                }
            }

            usort($result, fn($a, $b) => strtotime($b->dateIssued) - strtotime($a->dateIssued));

            $resource = new ContractListResource();
            $resource->contracts = $result;
            $resource->total = count($result);

            return $resource;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch contracts', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** @return array<int, array{price: float, volume: float}> */
    private function getPublicContractsForComparison(): array
    {
        try {
            $publicContracts = $this->esiClient->get(
                "/contracts/public/" . self::FORGE_REGION_ID . "/"
            );

            $contractPrices = [];
            foreach ($publicContracts as $contract) {
                if ($contract['type'] !== 'item_exchange') {
                    continue;
                }
                if (($contract['status'] ?? '') !== 'outstanding') {
                    continue;
                }

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

    /**
     * @param array<string, mixed> $contract
     * @param list<array<string, mixed>> $items
     * @param array<int, float|null> $jitaPrices
     * @param array<int, array{price: float, volume: float}> $publicContracts
     */
    private function processContract(array $contract, int $characterId, array $items, array $jitaPrices, array $publicContracts): ?ContractResource
    {
        try {
            $includedItems = array_filter($items, fn($item) => ($item['is_included'] ?? true));

            if (empty($includedItems)) {
                return null;
            }

            $typeIds = array_unique(array_column($includedItems, 'type_id'));
            $typeNames = $this->resolveTypeNames($typeIds);

            $itemsWithPrices = [];
            $jitaValue = 0;
            $delveValue = 0;

            foreach ($includedItems as $item) {
                $typeId = $item['type_id'];
                $quantity = $item['quantity'];
                $typeName = $typeNames[$typeId] ?? 'Unknown';

                $jitaPrice = $jitaPrices[$typeId] ?? null;
                $itemJitaValue = $jitaPrice !== null ? $jitaPrice * $quantity : null;

                $delvePrice = $this->structureMarketService->getLowestSellPrice($this->defaultMarketStructureId, $typeId);
                $itemDelveValue = $delvePrice !== null ? $delvePrice * $quantity : null;

                if ($itemJitaValue !== null) {
                    $jitaValue += $itemJitaValue;
                }
                if ($itemDelveValue !== null) {
                    $delveValue += $itemDelveValue;
                }

                $itemResource = new ContractItemResource();
                $itemResource->typeId = $typeId;
                $itemResource->typeName = $typeName;
                $itemResource->quantity = $quantity;
                $itemResource->jitaPrice = $jitaPrice;
                $itemResource->jitaValue = $itemJitaValue;
                $itemResource->delvePrice = $delvePrice;
                $itemResource->delveValue = $itemDelveValue;

                $itemsWithPrices[] = $itemResource;
            }

            $contractPrice = $contract['price'] ?? 0;
            $contractVolume = $contract['volume'] ?? 0;

            $similarContracts = $this->findSimilarPublicContracts($contractVolume, $publicContracts);

            $lowestSimilar = null;
            $avgSimilar = null;
            $similarCount = count($similarContracts);

            if ($similarCount > 0) {
                $prices = array_column($similarContracts, 'price');
                $lowestSimilar = min($prices);
                $avgSimilar = array_sum($prices) / $similarCount;
            }

            $isSeller = $contract['issuer_id'] === $characterId;

            $jitaDiff = $jitaValue > 0 ? $contractPrice - $jitaValue : null;
            $jitaDiffPercent = $jitaValue > 0 ? (($contractPrice - $jitaValue) / $jitaValue) * 100 : null;

            $delveDiff = $delveValue > 0 ? $contractPrice - $delveValue : null;
            $delveDiffPercent = $delveValue > 0 ? (($contractPrice - $delveValue) / $delveValue) * 100 : null;

            $similarDiff = $lowestSimilar !== null ? $contractPrice - $lowestSimilar : null;
            $similarDiffPercent = $lowestSimilar !== null && $lowestSimilar > 0
                ? (($contractPrice - $lowestSimilar) / $lowestSimilar) * 100
                : null;

            $isCompetitive = null;
            if ($similarDiffPercent !== null) {
                $isCompetitive = $similarDiffPercent <= 5;
            } elseif ($jitaDiffPercent !== null) {
                $isCompetitive = $jitaDiffPercent <= 10;
            }

            $resource = new ContractResource();
            $resource->contractId = $contract['contract_id'];
            $resource->type = $contract['type'];
            $resource->status = $contract['status'] ?? 'unknown';
            $resource->title = $contract['title'] ?? '';
            $resource->price = $contractPrice;
            $resource->reward = $contract['reward'] ?? 0;
            $resource->volume = $contractVolume;
            $resource->dateIssued = $contract['date_issued'];
            $resource->dateExpired = $contract['date_expired'] ?? null;
            $resource->dateCompleted = $contract['date_completed'] ?? null;
            $resource->issuerId = $contract['issuer_id'];
            $resource->assigneeId = $contract['assignee_id'] ?? null;
            $resource->acceptorId = $contract['acceptor_id'] ?? 0;
            $resource->forCorporation = $contract['for_corporation'] ?? false;
            $resource->isSeller = $isSeller;
            $resource->items = $itemsWithPrices;
            $resource->itemCount = count($itemsWithPrices);
            $resource->jitaValue = $jitaValue > 0 ? $jitaValue : null;
            $resource->jitaDiff = $jitaDiff;
            $resource->jitaDiffPercent = $jitaDiffPercent;
            $resource->delveValue = $delveValue > 0 ? $delveValue : null;
            $resource->delveDiff = $delveDiff;
            $resource->delveDiffPercent = $delveDiffPercent;
            $resource->similarCount = $similarCount;
            $resource->lowestSimilar = $lowestSimilar;
            $resource->avgSimilar = $avgSimilar;
            $resource->similarDiff = $similarDiff;
            $resource->similarDiffPercent = $similarDiffPercent;
            $resource->isCompetitive = $isCompetitive;

            return $resource;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to process contract', [
                'contractId' => $contract['contract_id'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param array<int, array{price: float, volume: float}> $publicContracts
     * @return list<array{price: float, volume: float}>
     */
    private function findSimilarPublicContracts(float $volume, array $publicContracts): array
    {
        if ($volume <= 0) {
            return [];
        }

        $tolerance = 0.02;
        $minVolume = $volume * (1 - $tolerance);
        $maxVolume = $volume * (1 + $tolerance);

        $similar = [];
        foreach ($publicContracts as $contractId => $contract) {
            $contractVolume = $contract['volume'];
            if ($contractVolume >= $minVolume && $contractVolume <= $maxVolume) {
                $similar[] = $contract;
            }
        }

        return $similar;
    }

    /**
     * @param array<int> $typeIds
     * @return array<int, string>
     */
    private function resolveTypeNames(array $typeIds): array
    {
        if (empty($typeIds)) {
            return [];
        }

        try {
            $response = $this->esiClient->post('/universe/names/', $typeIds);
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
