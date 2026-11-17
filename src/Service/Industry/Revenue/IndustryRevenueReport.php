<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

final readonly class IndustryRevenueReport
{
    /**
     * @param list<IndustryRevenueMonth> $months
     * @param list<IndustryRevenueProduct> $products
     * @param array<string, float> $dailyProfit
     */
    public function __construct(
        public IndustryRevenueTotals $totals,
        public array $months,
        public array $products,
        public array $dailyProfit = [],
    ) {
    }
}
