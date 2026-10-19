<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryStockpileImport;
use App\Entity\User;

class StockpileStartingStockReducer
{
    public function __construct(
        private readonly IndustryTreeService $treeService,
    ) {
    }

    /**
     * @param list<IndustryStockpileImport> $imports
     * @param array<int, int> $stockByTypeId
     * @return array{reductionByTypeId: array<int, int>, failedProducts: list<array{typeId: int, reason: string}>}
     */
    public function reduce(array $imports, array $stockByTypeId, User $user): array
    {
        return ['reductionByTypeId' => [], 'failedProducts' => []];
    }
}
