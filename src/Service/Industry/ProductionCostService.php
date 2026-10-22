<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Constant\EveConstants;
use App\Enum\IndustryActivityType;
use App\Industry\Domain\ActivityKind;
use App\Repository\Sde\IndustryActivityMaterialRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use App\Service\JitaMarketService;

/**
 * Calculates the full production cost breakdown for an IndustryProject:
 * material costs (Jita prices) + job install costs (ESI cost indices).
 */
class ProductionCostService
{

    public function __construct(
        private readonly JitaMarketService $jitaMarketService,
        private readonly EsiCostIndexService $esiCostIndexService,
        private readonly IndustryShoppingListBuilder $shoppingListBuilder,
        private readonly IndustryCalculationService $calculationService,
        private readonly ProjectStepSolarSystemResolver $solarSystemResolver,
        private readonly IndustryActivityMaterialRepository $materialRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
        private readonly FacilityTaxResolver $facilityTaxResolver,
    ) {
    }

    /**
     * Estimate the cost of raw materials needed for the project.
     *
     * @return array{total: float|null, items: list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}>, failedProducts: list<array{typeId: int, reason: string}>}
     */
    public function estimateMaterialCost(IndustryProject $project): array
    {
        $shoppingListResult = $this->shoppingListBuilder->getShoppingList($project);
        $shoppingList = $shoppingListResult['materials'];
        $failedProducts = $shoppingListResult['failedProducts'];

        $typeIds = array_map(fn (array $mat) => $mat['typeId'], $shoppingList);
        $prices = $this->jitaMarketService->getPricesWithFallback($typeIds);

        $total = 0.0;
        $items = [];

        foreach ($shoppingList as $material) {
            $typeId = $material['typeId'];
            $quantity = $material['quantity'];
            $unitPrice = $prices[$typeId] ?? 0.0;
            $totalPrice = $unitPrice * $quantity;
            $total += $totalPrice;

            $items[] = [
                'typeId' => $typeId,
                'typeName' => $material['typeName'],
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'totalPrice' => $totalPrice,
            ];
        }

        return [
            'total' => $failedProducts === [] ? $total : null,
            'items' => $items,
            'failedProducts' => $failedProducts,
        ];
    }

    /**
     * Estimate job install costs for all manufacturing/reaction steps in the project.
     *
     * @return array{total: float|null, steps: list<array{stepId: string, productTypeId: int, productName: string|null, solarSystemId: int, systemName: string, costIndex: float|null, runs: int, installCost: float|null, unknownReason: string|null, missingAdjustedPriceTypeIds: list<int>}>}
     */
    public function estimateJobInstallCosts(IndustryProject $project): array
    {
        $total = 0.0;
        $isTotalUnknown = false;
        $stepResults = [];

        foreach ($project->getSteps() as $step) {
            $activityType = $step->getActivityType();

            if ($activityType !== 'manufacturing' && $activityType !== 'reaction') {
                continue;
            }

            if ($step->isPurchased() || $step->isInStock()) {
                continue;
            }

            $solarSystemId = $this->solarSystemResolver->forStep($step);
            $activityKind = $activityType === 'reaction' ? ActivityKind::Reaction : ActivityKind::Manufacturing;
            $facilityTaxPercent = $step->getStructureConfig() !== null
                ? $step->getStructureConfig()->getFacilityTaxPercent()
                : $this->facilityTaxResolver->percentFor($project->getUser(), $solarSystemId, $activityKind);

            $activityIdValue = $activityType === 'reaction' ? IndustryActivityType::Reaction->value : IndustryActivityType::Manufacturing->value;
            $me0Materials = $this->materialRepository->findMaterialsForBlueprints(
                [$step->getBlueprintTypeId()],
                [$activityIdValue],
            );

            $estimate = $this->esiCostIndexService->estimateJobInstallCost(
                $me0Materials[$step->getBlueprintTypeId()] ?? [],
                $step->getRuns(),
                $solarSystemId,
                $activityType,
                $facilityTaxPercent,
            );

            if ($estimate['cost'] === null) {
                $isTotalUnknown = true;
            } else {
                $total += $estimate['cost'];
            }

            $stepResults[] = [
                'stepId' => $step->getId()?->toRfc4122() ?? '',
                'productTypeId' => $step->getProductTypeId(),
                'productName' => $this->calculationService->resolveTypeName($step->getProductTypeId()),
                'solarSystemId' => $solarSystemId,
                'systemName' => $this->resolveSystemName($solarSystemId),
                'costIndex' => $this->esiCostIndexService->getCostIndex($solarSystemId, $activityType),
                'runs' => $step->getRuns(),
                'installCost' => $estimate['cost'],
                'unknownReason' => $estimate['unknownReason'],
                'missingAdjustedPriceTypeIds' => $estimate['missingAdjustedPriceTypeIds'],
            ];
        }

        return [
            'total' => $isTotalUnknown ? null : $total,
            'steps' => $stepResults,
        ];
    }

    /**
     * Estimate the total production cost with full breakdown.
     *
     * @return array{materialCost: float|null, jobInstallCost: float|null, bpoCost: float, totalCost: float|null, perUnit: float|null, materials: list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}>, jobInstallSteps: list<array{stepId: string, productTypeId: int, productName: string|null, solarSystemId: int, systemName: string, costIndex: float|null, runs: int, installCost: float|null, unknownReason: string|null, missingAdjustedPriceTypeIds: list<int>}>, failedProducts: list<array{typeId: int, reason: string}>}
     */
    public function estimateTotalCost(IndustryProject $project): array
    {
        $materialResult = $this->estimateMaterialCost($project);
        $jobInstallResult = $this->estimateJobInstallCosts($project);
        $bpoCost = $project->getBpoCost() ?? 0.0;

        $totalCost = $materialResult['total'] === null || $jobInstallResult['total'] === null
            ? null
            : $materialResult['total'] + $jobInstallResult['total'] + $bpoCost;

        $totalOutputQuantity = $this->calculateTotalOutputQuantity($project);
        $perUnit = match (true) {
            $totalCost === null => null,
            $totalOutputQuantity > 0 => $totalCost / $totalOutputQuantity,
            default => 0.0,
        };

        return [
            'materialCost' => $materialResult['total'],
            'jobInstallCost' => $jobInstallResult['total'],
            'bpoCost' => $bpoCost,
            'totalCost' => $totalCost,
            'perUnit' => $perUnit,
            'materials' => $materialResult['items'],
            'jobInstallSteps' => $jobInstallResult['steps'],
            'failedProducts' => $materialResult['failedProducts'],
        ];
    }

    /**
     * Resolve the display name for a solar system.
     */
    private function resolveSystemName(int $solarSystemId): string
    {
        $system = $this->solarSystemRepository->findBySolarSystemId($solarSystemId);

        return $system?->getSolarSystemName() ?? "System #{$solarSystemId}";
    }

    /**
     * Calculate the total output quantity for the project (runs * output per run).
     */
    private function calculateTotalOutputQuantity(IndustryProject $project): int
    {
        $product = $this->productRepository->findBlueprintForProduct(
            $project->getProductTypeId(),
            IndustryActivityType::Manufacturing->value,
        );

        $outputPerRun = $product?->getQuantity() ?? 1;

        return $project->getRuns() * $outputPerRun;
    }
}
