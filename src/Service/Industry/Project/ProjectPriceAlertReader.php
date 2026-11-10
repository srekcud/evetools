<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Enum\ProjectStatus;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketComparisonResult;
use App\Service\Industry\SellMarkets\SellMarketRow;
use Symfony\Component\Clock\ClockInterface;

class ProjectPriceAlertReader
{
    public function __construct(
        private readonly ProjectDistributionService $distributionService,
        private readonly SellMarketComparison $comparison,
        private readonly UnsoldProductsOfProject $unsoldProducts,
        private readonly ClockInterface $clock,
    ) {
    }

    public function of(IndustryProject $project): ?ProjectPriceAlert
    {
        if ($project->isPersonalUse() || $project->getStatus() === ProjectStatus::Completed) {
            return null;
        }

        $breakEvenPerUnit = $this->distributionService->calculateDistribution($project)->breakEvenPerUnit;
        if ($breakEvenPerUnit === null) {
            return null;
        }

        $unsold = $this->unsoldProducts->of($project, $project->getSales())[0] ?? null;
        if ($unsold === null) {
            return null;
        }

        $comparison = $this->comparison->compareAt($project->getOwner(), null, $unsold->typeId, $unsold->unsoldQuantity, $this->clock->now());
        $jitaUnitPrice = $this->jitaRowOf($comparison)->unitPrice;
        if ($jitaUnitPrice === null || $jitaUnitPrice >= $breakEvenPerUnit) {
            return null;
        }

        return new ProjectPriceAlert($unsold->typeId, $unsold->typeName, $unsold->unsoldQuantity, $jitaUnitPrice, $breakEvenPerUnit);
    }

    private function jitaRowOf(SellMarketComparisonResult $comparison): SellMarketRow
    {
        foreach ($comparison->markets as $row) {
            if ($row->key === SellMarketComparison::JITA_KEY) {
                return $row;
            }
        }

        throw new \UnexpectedValueException(\sprintf('The comparison of type %d has no "%s" market', $comparison->typeId, SellMarketComparison::JITA_KEY));
    }
}
