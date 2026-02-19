<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Repository\IndustryUserSettingsRepository;
use App\Constant\EveConstants;
use App\Enum\IndustryActivityType;
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
        private readonly IndustryUserSettingsRepository $userSettingsRepository,
        private readonly IndustryActivityProductRepository $productRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    /**
     * Estimate the cost of raw materials needed for the project.
     *
     * @return array{total: float, items: list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}>}
     */
    public function estimateMaterialCost(IndustryProject $project): array
    {
        $shoppingList = $this->shoppingListBuilder->getShoppingList($project);

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
            'total' => $total,
            'items' => $items,
        ];
    }

    /**
     * Estimate job install costs for all manufacturing/reaction steps in the project.
     *
     * @return array{total: float, steps: list<array{stepId: string, productTypeId: int, productName: string, solarSystemId: int, systemName: string, costIndex: float, runs: int, installCost: float}>}
     */
    public function estimateJobInstallCosts(IndustryProject $project): array
    {
        $total = 0.0;
        $stepResults = [];

        foreach ($project->getSteps() as $step) {
            $activityType = $step->getActivityType();

            if ($activityType !== 'manufacturing' && $activityType !== 'reaction') {
                continue;
            }

            $esiActivity = $activityType; // ESI uses 'manufacturing' and 'reaction' directly
            $solarSystemId = $this->resolveSolarSystemForStep($step, $project);
            $facilityTaxRate = $step->getStructureConfig()?->getFacilityTaxRate();

            $installCost = $this->esiCostIndexService->calculateJobInstallCost(
                $step->getProductTypeId(),
                $step->getRuns(),
                $solarSystemId,
                $esiActivity,
                $facilityTaxRate,
            );

            $costIndex = $this->esiCostIndexService->getCostIndex($solarSystemId, $esiActivity);
            $systemName = $this->resolveSystemName($solarSystemId);

            $total += $installCost;

            $stepResults[] = [
                'stepId' => $step->getId()?->toRfc4122() ?? '',
                'productTypeId' => $step->getProductTypeId(),
                'productName' => $this->calculationService->resolveTypeName($step->getProductTypeId()),
                'solarSystemId' => $solarSystemId,
                'systemName' => $systemName,
                'costIndex' => $costIndex ?? 0.0,
                'runs' => $step->getRuns(),
                'installCost' => $installCost,
            ];
        }

        return [
            'total' => $total,
            'steps' => $stepResults,
        ];
    }

    /**
     * Estimate the total production cost with full breakdown.
     *
     * @return array{materialCost: float, jobInstallCost: float, bpoCost: float, totalCost: float, perUnit: float, materials: list<array{typeId: int, typeName: string, quantity: int, unitPrice: float, totalPrice: float}>, jobInstallSteps: list<array{stepId: string, productTypeId: int, productName: string, solarSystemId: int, systemName: string, costIndex: float, runs: int, installCost: float}>}
     */
    public function estimateTotalCost(IndustryProject $project): array
    {
        $materialResult = $this->estimateMaterialCost($project);
        $jobInstallResult = $this->estimateJobInstallCosts($project);
        $bpoCost = $project->getBpoCost() ?? 0.0;

        $totalCost = $materialResult['total'] + $jobInstallResult['total'] + $bpoCost;

        $totalOutputQuantity = $this->calculateTotalOutputQuantity($project);
        $perUnit = $totalOutputQuantity > 0 ? $totalCost / $totalOutputQuantity : 0.0;

        return [
            'materialCost' => $materialResult['total'],
            'jobInstallCost' => $jobInstallResult['total'],
            'bpoCost' => $bpoCost,
            'totalCost' => $totalCost,
            'perUnit' => $perUnit,
            'materials' => $materialResult['items'],
            'jobInstallSteps' => $jobInstallResult['steps'],
        ];
    }

    /**
     * Resolve the solar system for a given step, with fallback chain:
     * 1. Step's structure config solarSystemId
     * 2. User's favorite system (manufacturing or reaction)
     * 3. Default: Perimeter (30000142)
     */
    private function resolveSolarSystemForStep(IndustryProjectStep $step, IndustryProject $project): int
    {
        // 1. Step's assigned structure config
        $structureConfig = $step->getStructureConfig();
        if ($structureConfig !== null && $structureConfig->getSolarSystemId() !== null) {
            return $structureConfig->getSolarSystemId();
        }

        // 2. User's favorite system for this activity type
        $user = $project->getUser();
        $settings = $this->userSettingsRepository->findOneBy(['user' => $user]);
        if ($settings !== null) {
            $isReaction = $step->getActivityType() === 'reaction';
            $favoriteSystemId = $isReaction
                ? $settings->getFavoriteReactionSystemId()
                : $settings->getFavoriteManufacturingSystemId();

            if ($favoriteSystemId !== null) {
                return $favoriteSystemId;
            }
        }

        // 3. Default fallback
        return EveConstants::PERIMETER_SOLAR_SYSTEM_ID;
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
