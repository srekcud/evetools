<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Market;

use App\Industry\Application\StructureMarketPrices;
use App\Industry\Domain\Isk;
use App\Service\StructureMarketService;

final readonly class StructureMarketCachePrices implements StructureMarketPrices
{
    public function __construct(private StructureMarketService $structureMarketService)
    {
    }

    public function isSynced(int $structureId): bool
    {
        return $this->structureMarketService->hasCachedData($structureId);
    }

    public function lastSyncOf(int $structureId): ?\DateTimeImmutable
    {
        return $this->structureMarketService->getLastSyncTime($structureId);
    }

    public function unitPricesFor(int $structureId, array $typeIds): array
    {
        $unitPrices = [];
        foreach ($this->structureMarketService->getLowestSellPrices($structureId, $typeIds) as $typeId => $lowestSellPrice) {
            if (null !== $lowestSellPrice) {
                $unitPrices[$typeId] = new Isk($lowestSellPrice);
            }
        }

        return $unitPrices;
    }
}
