<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketHistoryEntryResource;
use App\ApiResource\Market\MarketHistoryResource;
use App\Service\MarketHistoryService;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<MarketHistoryResource>
 */
class MarketHistoryProvider implements ProviderInterface
{
    public function __construct(
        private readonly MarketHistoryService $marketHistoryService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MarketHistoryResource
    {
        $typeId = (int) ($uriVariables['typeId'] ?? 0);
        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', '30') ?? '30');

        // Clamp days to reasonable range
        $days = max(1, min(365, $days));

        $resource = new MarketHistoryResource();
        $resource->typeId = $typeId;

        $entries = $this->marketHistoryService->getHistory($typeId, $days);

        $resource->entries = array_map(static function ($entry) {
            $item = new MarketHistoryEntryResource();
            $item->date = $entry->getDate()->format('Y-m-d');
            $item->average = $entry->getAverage();
            $item->highest = $entry->getHighest();
            $item->lowest = $entry->getLowest();
            $item->orderCount = $entry->getOrderCount();
            $item->volume = $entry->getVolume();

            return $item;
        }, $entries);

        return $resource;
    }
}
