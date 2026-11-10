<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Service\Industry\SellMarkets\SellMarketComparison;
use App\Service\Industry\SellMarkets\SellMarketComparisonResult;
use App\Service\Industry\SellMarkets\SellMarketRow;

final readonly class ProjectSellForecast
{
    public function __construct(
        private SellMarketComparison $comparison,
        private UnsoldProductsOfProject $unsoldProducts,
        private RemainingBomCost $remainingBomCost,
    ) {
    }

    public function forecast(IndustryProject $project, \DateTimeImmutable $today): SellForecast
    {
        $sellStructure = $this->sellStructureOf($project);
        $products = [];
        foreach ($this->unsoldProducts->of($project, $project->getSales()) as $unsold) {
            $comparison = $this->comparison->compareAt($project->getOwner(), $sellStructure, $unsold->typeId, $unsold->unsoldQuantity, $today);
            $products[] = new ForecastedProduct(
                $unsold->typeId,
                $unsold->typeName,
                $unsold->unsoldQuantity,
                null === $sellStructure ? null : $this->rowOf($comparison, SellMarketComparison::CAPITAL_KEY),
                $this->rowOf($comparison, SellMarketComparison::JITA_KEY),
            );
        }
        usort($products, static fn (ForecastedProduct $first, ForecastedProduct $second): int => $first->typeName <=> $second->typeName);

        $structureRevenues = null === $sellStructure ? [] : array_map(static fn (ForecastedProduct $product): ?float => $product->structureNetRevenue(), $products);
        $jitaRevenues = array_map(static fn (ForecastedProduct $product): ?float => $product->jitaNetRevenue(), $products);

        $structureNetRevenue = $this->totalOf($structureRevenues);
        $jitaNetRevenue = $this->totalOf($jitaRevenues);
        $remainingCost = $this->remainingBomCost->of($project);

        return new SellForecast(
            $sellStructure,
            $products,
            $structureNetRevenue,
            $jitaNetRevenue,
            $this->unknownCountOf($structureRevenues),
            $this->unknownCountOf($jitaRevenues),
            $remainingCost->total,
            $remainingCost->unknownLineCount,
            $this->profitOf($structureNetRevenue, $remainingCost->total),
            $this->profitOf($jitaNetRevenue, $remainingCost->total),
        );
    }

    private function profitOf(?float $netRevenue, ?float $remainingCost): ?float
    {
        return null === $netRevenue || null === $remainingCost ? null : $netRevenue - $remainingCost;
    }

    private function sellStructureOf(IndustryProject $project): ?SellStructure
    {
        $id = $project->getSellStructureId();
        if (null === $id) {
            return null;
        }

        return new SellStructure($id, $project->getSellStructureName() ?? throw new \UnexpectedValueException(\sprintf('Sell structure %d of project %s has no name', $id, $project->getId()->toRfc4122())));
    }

    private function rowOf(SellMarketComparisonResult $comparison, string $key): SellMarketRow
    {
        foreach ($comparison->markets as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        throw new \UnexpectedValueException(\sprintf('The comparison of type %d has no "%s" market', $comparison->typeId, $key));
    }

    /**
     * @param list<?float> $revenues
     */
    private function totalOf(array $revenues): ?float
    {
        if ($revenues === [] || \in_array(null, $revenues, true)) {
            return null;
        }

        return array_sum($revenues);
    }

    /**
     * @param list<?float> $revenues
     */
    private function unknownCountOf(array $revenues): int
    {
        return \count(array_filter($revenues, static fn (?float $revenue): bool => null === $revenue));
    }
}
