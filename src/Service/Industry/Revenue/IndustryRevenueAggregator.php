<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

class IndustryRevenueAggregator
{
    /**
     * @param list<CompletedProjectFigures> $figures
     */
    public function aggregate(array $figures): IndustryRevenueReport
    {
        return new IndustryRevenueReport(new IndustryRevenueTotals(0.0, 0, 0, 0.0, null, null), [], []);
    }
}
