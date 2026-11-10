<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectBomLine;

final readonly class ProjectBreakEven
{
    private const string FINAL_JOB_GROUP = 'final';
    private const float PERCENT = 100.0;

    public const string NO_FINAL_PRODUCT = 'no_final_product';
    public const string SEVERAL_FINAL_PRODUCTS = 'several_final_products';
    public const string JOBS_NOT_DELIVERED = 'jobs_not_delivered';
    public const string COST_UNKNOWN = 'cost_unknown';
    public const string NO_COST = 'no_cost';
    public const string FEES_UNKNOWN = 'fees_unknown';

    public function of(IndustryProject $project, ?float $totalProjectCost): BreakEven
    {
        $production = $this->productionOf($project, $totalProjectCost);
        if (is_string($production)) {
            return BreakEven::unknown($production);
        }

        [$units, $cost] = $production;
        $brokerFeePercent = $project->getBrokerFeePercent();
        $salesTaxPercent = $project->getSalesTaxPercent();
        if ($brokerFeePercent === null || $salesTaxPercent === null) {
            return BreakEven::unknown(self::FEES_UNKNOWN);
        }

        $keptRatio = 1 - ($brokerFeePercent + $salesTaxPercent) / self::PERCENT;
        if ($keptRatio <= 0.0) {
            return BreakEven::unknown(self::FEES_UNKNOWN);
        }

        return BreakEven::known($cost / ($units * $keptRatio));
    }

    public function costPerUnitOf(IndustryProject $project, ?float $totalProjectCost): BreakEven
    {
        $production = $this->productionOf($project, $totalProjectCost);
        if (is_string($production)) {
            return BreakEven::unknown($production);
        }

        [$units, $cost] = $production;

        return BreakEven::known($cost / $units);
    }

    /**
     * @return string|array{int, float}
     */
    private function productionOf(IndustryProject $project, ?float $totalProjectCost): string|array
    {
        $finalLines = array_values(array_filter(
            $project->getBomItems()->toArray(),
            static fn (IndustryProjectBomLine $line): bool => $line->isJob() && $line->getJobGroup() === self::FINAL_JOB_GROUP,
        ));

        if ($finalLines === []) {
            return self::NO_FINAL_PRODUCT;
        }

        $typeIds = array_unique(array_map(static fn (IndustryProjectBomLine $line): int => $line->getTypeId(), $finalLines));
        if (count($typeIds) > 1) {
            return self::SEVERAL_FINAL_PRODUCTS;
        }

        foreach ($finalLines as $line) {
            if (!$line->isFulfilled()) {
                return self::JOBS_NOT_DELIVERED;
            }
        }

        if ($totalProjectCost === null) {
            return self::COST_UNKNOWN;
        }

        if ($totalProjectCost <= 0.0) {
            return self::NO_COST;
        }

        $units = array_sum(array_map(static fn (IndustryProjectBomLine $line): int => $line->getRequiredQuantity(), $finalLines));
        if ($units <= 0) {
            return self::NO_FINAL_PRODUCT;
        }

        return [$units, $totalProjectCost];
    }
}
