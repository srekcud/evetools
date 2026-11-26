<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectSale;
use App\Service\Industry\SellOrders\ProjectProductTypes;

final readonly class UnmatchedSalesOfProject
{
    public function __construct(
        private ProjectProductTypes $productTypes = new ProjectProductTypes(),
    ) {}

    /**
     * @param iterable<IndustryProjectSale> $sales
     *
     * @return list<UnmatchedSale>
     */
    public function of(IndustryProject $project, iterable $sales): array
    {
        $finalProductNames = $this->productTypes->of($project);

        $totalsByTypeId = [];
        foreach ($sales as $sale) {
            $typeId = $sale->getTypeId();
            if (isset($finalProductNames[$typeId])) {
                continue;
            }

            $totalsByTypeId[$typeId] = [
                'typeName' => $totalsByTypeId[$typeId]['typeName'] ?? $sale->getTypeName(),
                'quantity' => ($totalsByTypeId[$typeId]['quantity'] ?? 0) + $sale->getQuantity(),
                'revenue' => ($totalsByTypeId[$typeId]['revenue'] ?? 0.0) + $sale->getTotalPrice(),
            ];
        }

        $unmatchedSales = [];
        foreach ($totalsByTypeId as $typeId => $totals) {
            $unmatchedSales[] = new UnmatchedSale($typeId, $totals['typeName'], $totals['quantity'], $totals['revenue']);
        }
        usort($unmatchedSales, static fn (UnmatchedSale $first, UnmatchedSale $second): int => $first->typeName <=> $second->typeName);

        return $unmatchedSales;
    }
}
