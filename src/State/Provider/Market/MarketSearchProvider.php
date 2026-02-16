<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketSearchItemResource;
use App\ApiResource\Market\MarketSearchResource;
use App\Entity\Sde\InvType;
use App\Repository\Sde\InvTypeRepository;
use App\Service\JitaMarketService;
use App\Service\MarketHistoryService;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<MarketSearchResource>
 */
class MarketSearchProvider implements ProviderInterface
{
    public function __construct(
        private readonly InvTypeRepository $invTypeRepository,
        private readonly JitaMarketService $jitaMarketService,
        private readonly MarketHistoryService $marketHistoryService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MarketSearchResource
    {
        $request = $this->requestStack->getCurrentRequest();
        $query = $request?->query->get('q', '') ?? '';

        $resource = new MarketSearchResource();

        if (strlen($query) < 2) {
            return $resource;
        }

        // Search published items with marketGroupId (marketable items only)
        $types = $this->invTypeRepository->searchByName($query, 20);

        // Filter to items with a market group
        $marketableTypes = array_filter(
            $types,
            static fn (InvType $type) => $type->getMarketGroup() !== null,
        );

        if (empty($marketableTypes)) {
            return $resource;
        }

        $typeIds = array_map(
            static fn (InvType $type) => $type->getTypeId(),
            array_values($marketableTypes),
        );

        // Batch-load prices
        $sellPrices = $this->jitaMarketService->getPrices($typeIds);
        $buyPrices = $this->jitaMarketService->getBuyPrices($typeIds);

        foreach ($marketableTypes as $type) {
            $item = new MarketSearchItemResource();
            $item->typeId = $type->getTypeId();
            $item->typeName = $type->getTypeName();
            $item->groupName = $type->getGroup()->getGroupName();
            $item->categoryName = $type->getGroup()->getCategory()->getCategoryName();

            $sell = $sellPrices[$type->getTypeId()] ?? null;
            $buy = $buyPrices[$type->getTypeId()] ?? null;
            $item->jitaSell = $sell;
            $item->jitaBuy = $buy;

            if ($sell !== null && $buy !== null && $sell > 0) {
                $item->spread = round(($sell - $buy) / $sell * 100, 2);
            }

            $item->change30d = $this->marketHistoryService->get30dPriceChange($type->getTypeId());

            $resource->results[] = $item;
        }

        return $resource;
    }
}
