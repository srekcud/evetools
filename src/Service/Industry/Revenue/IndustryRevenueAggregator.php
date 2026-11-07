<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

class IndustryRevenueAggregator
{
    private const string MONTH_FORMAT = 'Y-m';
    private const int PERCENT = 100;

    /**
     * @param list<CompletedProjectFigures> $figures
     */
    public function aggregate(array $figures): IndustryRevenueReport
    {
        $totals = $this->summaryOf($figures);

        return new IndustryRevenueReport(
            new IndustryRevenueTotals(
                $totals['profit'],
                $totals['projectCount'],
                $totals['unknownProjectCount'],
                $totals['capital'],
                $totals['slotHours'],
                $totals['iskPerHour'],
            ),
            $this->months($figures),
            $this->products($figures),
        );
    }

    /**
     * @param list<CompletedProjectFigures> $figures
     *
     * @return list<IndustryRevenueMonth>
     */
    private function months(array $figures): array
    {
        $figuresByMonth = [];
        foreach ($figures as $project) {
            $figuresByMonth[$project->completedAt->setTimezone(new \DateTimeZone('UTC'))->format(self::MONTH_FORMAT)][] = $project;
        }
        krsort($figuresByMonth);

        $months = [];
        foreach ($figuresByMonth as $month => $monthFigures) {
            $summary = $this->summaryOf($monthFigures);
            $months[] = new IndustryRevenueMonth(
                (string) $month,
                $summary['projectCount'],
                $summary['unknownProjectCount'],
                $summary['profit'],
                $summary['capital'],
                $summary['capital'] > 0 ? $summary['profit'] * self::PERCENT / $summary['capital'] : null,
                $summary['slotHours'],
                $summary['iskPerHour'],
            );
        }

        return $months;
    }

    /**
     * @param list<CompletedProjectFigures> $figures
     *
     * @return list<IndustryRevenueProduct>
     */
    private function products(array $figures): array
    {
        $figuresByProduct = [];
        foreach ($figures as $project) {
            $figuresByProduct[$project->hasMultipleProducts ? 'multiple' : (string) $project->productTypeId][] = $project;
        }

        $products = [];
        foreach ($figuresByProduct as $productFigures) {
            $summary = $this->summaryOf($productFigures);
            $first = $productFigures[0];
            $products[] = new IndustryRevenueProduct(
                $first->hasMultipleProducts ? null : $first->productTypeId,
                $first->hasMultipleProducts ? null : $first->productTypeName,
                $summary['projectCount'],
                $summary['unknownProjectCount'],
                $summary['profit'],
            );
        }

        usort($products, static fn (IndustryRevenueProduct $a, IndustryRevenueProduct $b): int => [$b->profit, (string) $a->typeName] <=> [$a->profit, (string) $b->typeName]);

        return $products;
    }

    /**
     * @param list<CompletedProjectFigures> $figures
     *
     * @return array{profit: float, capital: float, projectCount: int, unknownProjectCount: int, slotHours: ?float, iskPerHour: ?float}
     */
    private function summaryOf(array $figures): array
    {
        $profit = 0.0;
        $capital = 0.0;
        $unknownProjectCount = 0;
        $slotHours = null;
        $profitWithHours = 0.0;
        $positiveHours = 0.0;

        foreach ($figures as $project) {
            if ($project->profit === null || $project->capital === null) {
                ++$unknownProjectCount;

                continue;
            }

            $profit += $project->profit;
            $capital += $project->capital;

            if ($project->slotHours === null) {
                continue;
            }

            $slotHours = ($slotHours ?? 0.0) + $project->slotHours;
            if ($project->slotHours > 0) {
                $profitWithHours += $project->profit;
                $positiveHours += $project->slotHours;
            }
        }

        return [
            'profit' => $profit,
            'capital' => $capital,
            'projectCount' => count($figures),
            'unknownProjectCount' => $unknownProjectCount,
            'slotHours' => $slotHours,
            'iskPerHour' => $positiveHours > 0 ? $profitWithHours / $positiveHours : null,
        ];
    }
}
