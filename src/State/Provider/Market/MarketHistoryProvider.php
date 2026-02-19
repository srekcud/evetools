<?php

declare(strict_types=1);

namespace App\State\Provider\Market;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Market\MarketHistoryEntryResource;
use App\ApiResource\Market\MarketHistoryResource;
use App\Entity\User;
use App\Repository\StructureMarketSnapshotRepository;
use App\Service\MarketHistoryService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<MarketHistoryResource>
 */
class MarketHistoryProvider implements ProviderInterface
{
    public function __construct(
        private readonly MarketHistoryService $marketHistoryService,
        private readonly StructureMarketSnapshotRepository $snapshotRepository,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly int $defaultMarketStructureId,
        private readonly string $defaultMarketStructureName,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MarketHistoryResource
    {
        $typeId = (int) ($uriVariables['typeId'] ?? 0);
        $request = $this->requestStack->getCurrentRequest();
        $days = (int) ($request?->query->get('days', '30') ?? '30');
        $source = $request?->query->get('source', 'jita') ?? 'jita';

        // Clamp days to reasonable range
        $days = max(1, min(365, $days));

        $resource = new MarketHistoryResource();
        $resource->typeId = $typeId;
        $resource->source = $source;

        if ($source === 'structure') {
            $this->provideStructureHistory($resource, $typeId, $days);
        } else {
            $this->provideJitaHistory($resource, $typeId, $days);
        }

        return $resource;
    }

    private function provideJitaHistory(MarketHistoryResource $resource, int $typeId, int $days): void
    {
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
    }

    private function provideStructureHistory(MarketHistoryResource $resource, int $typeId, int $days): void
    {
        $user = $this->security->getUser();
        $structureId = $this->defaultMarketStructureId;
        $structureName = $this->defaultMarketStructureName;

        if ($user instanceof User) {
            $structureId = $user->getPreferredMarketStructureId() ?? $this->defaultMarketStructureId;
            $structureName = $user->getPreferredMarketStructureName() ?? $this->defaultMarketStructureName;
        }

        $resource->structureId = $structureId;
        $resource->structureName = $structureName;

        // Clamp structure history to 90 days (snapshot retention)
        $days = min($days, 90);

        $snapshots = $this->snapshotRepository->findHistory($structureId, $typeId, $days);

        $resource->entries = array_map(static function ($snapshot) {
            $item = new MarketHistoryEntryResource();
            $item->date = $snapshot->getDate()->format('Y-m-d');
            // For structure snapshots, use sellMin as the primary price indicators
            $item->average = $snapshot->getSellMin() ?? 0.0;
            $item->highest = $snapshot->getSellMin() ?? 0.0;
            $item->lowest = $snapshot->getSellMin() ?? 0.0;
            $item->orderCount = $snapshot->getSellOrderCount() + $snapshot->getBuyOrderCount();
            $item->volume = $snapshot->getSellVolume() + $snapshot->getBuyVolume();
            // Structure-specific fields
            $item->sellMin = $snapshot->getSellMin();
            $item->buyMax = $snapshot->getBuyMax();
            $item->sellOrderCount = $snapshot->getSellOrderCount();
            $item->buyOrderCount = $snapshot->getBuyOrderCount();
            $item->sellVolume = $snapshot->getSellVolume();
            $item->buyVolume = $snapshot->getBuyVolume();

            return $item;
        }, $snapshots);
    }
}
