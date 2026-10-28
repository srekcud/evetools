<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Industry\Application\CostMode;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\InventionSettings;
use App\Industry\Application\LeafCost;
use App\Industry\Application\ProductionCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Application\ProductionStructure;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintCatalog;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\ProductionPlanner;
use App\Industry\Domain\Recipe;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;

class BuyVsBuildService
{
    private const string VERDICT_BUILD = 'build';
    private const string VERDICT_BUY = 'buy';
    private const string VERDICT_UNKNOWN = 'unknown';

    private const string STAGE_REACTION = 'Reaction';
    private const string STAGE_T2_COMPONENT = 'T2 Component';
    private const string STAGE_T1_COMPONENT = 'T1 Component';

    private const int INVENTION_SKILL_LEVEL_WITHOUT_SKILLS = 0;

    private const int ROOT_JOB_TIME_EFFICIENCY = 0;

    private const int DISPLAYED_DECIMALS = 2;

    private const float PERCENT = 100.0;

    private const float NO_SAVINGS_PERCENT = 0.0;

    public function __construct(
        private readonly ProductionCostCalculator $productionCosts,
        private readonly BlueprintCatalog $blueprints,
        private readonly UserProductionStructures $productionStructures,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly TypeNameResolver $typeNameResolver,
    ) {
    }

    /**
     * @return array{
     *     typeId: int,
     *     typeName: string|null,
     *     isT2: bool,
     *     runs: int,
     *     totalProductionCost: float|null,
     *     sellPrice: float|null,
     *     marginPercent: float|null,
     *     components: list<array{typeId: int, typeName: string|null, quantity: int, stage: string, buildCost: float|null, buildMaterials: list<array{typeId: int, typeName: string|null, quantity: int, unitPrice: float|null, totalPrice: float|null}>, buildJobInstallCost: float|null, buyCostJita: float|null, buyCostStructure: float|null, buyCostJitaCoveragePercent: float|null, verdict: string, savings: float|null, savingsPercent: float|null, meUsed: int, runs: int, missingData: list<array{reason: string, typeId: ?int}>, warnings: list<array{reason: string, typeId: int, coveragePercent: float}>}>,
     *     buildAllCost: float|null,
     *     buyAllCost: float|null,
     *     optimalMixCost: float|null,
     *     buildTypeIds: list<int>,
     *     buyTypeIds: list<int>,
     *     missingData: list<array{reason: string, typeId: ?int}>,
     *     warnings: list<array{reason: string, typeId: int, coveragePercent: float}>,
     * }
     */
    public function analyze(
        int $typeId,
        int $runs,
        int $meLevel,
        int $solarSystemId,
        ?int $sellStructureId,
        float $brokerFeeRate,
        float $salesTaxRate,
        ?User $user,
        ?int $reactionSolarSystemId = null,
    ): array {
        $structures = null === $user ? [] : $this->productionStructures->configuredBy(self::idOf($user));
        $favoriteSystems = new FavoriteSystems($solarSystemId, $reactionSolarSystemId ?? $solarSystemId);
        $componentRecipes = $this->componentRecipesOf($typeId);

        $rootCost = $this->costOf(
            $typeId,
            new Runs($runs),
            new BlueprintEfficiency(new MaterialEfficiency($meLevel), new TimeEfficiency(self::ROOT_JOB_TIME_EFFICIENCY)),
            array_keys($componentRecipes),
            $structures,
            $favoriteSystems,
        );

        $structureBuyPrices = null !== $sellStructureId && [] !== $componentRecipes
            ? $this->structureMarketService->getLowestSellPrices($sellStructureId, array_keys($componentRecipes))
            : [];

        $zero = Cost::known(new Isk(0.0));
        $buildAllCost = $zero;
        $buyAllCost = $zero;
        $optimalMixCost = $zero;
        $componentsMissingData = $zero;
        $buildTypeIds = [];
        $buyTypeIds = [];
        $components = [];

        foreach ($componentRecipes as $componentTypeId => $componentRecipe) {
            $rootLeaf = $rootCost->leaves[$componentTypeId]
                ?? throw new \LogicException(\sprintf('Component %d is blacklisted in the root job, so it is bought there.', $componentTypeId));
            $quantity = (int) $rootLeaf->quantity;
            $componentRuns = (int) ceil($quantity / $componentRecipe->outputPerRun->value);
            $efficiency = self::defaultEfficiencyOf($componentRecipe);
            $componentCost = $this->costOf(
                $componentTypeId,
                new Runs($componentRuns),
                $efficiency,
                array_map(static fn (RecipeMaterial $material): int => $material->typeId, $componentRecipe->materials),
                $structures,
                $favoriteSystems,
            );

            $structureBuyPrice = $structureBuyPrices[$componentTypeId] ?? null;
            $buyCostStructure = null === $structureBuyPrice ? null : Cost::known(new Isk($structureBuyPrice * $quantity));
            $buildCost = $componentCost->totalCost;
            $buyCost = $buyCostStructure ?? $rootLeaf->cost;
            $verdict = self::verdictOf($buildCost, $buyCost);
            $savings = $buildCost->isKnown() && $buyCost->isKnown() ? $buyCost->amount()->amount - $buildCost->amount()->amount : null;

            $buildAllCost = $buildAllCost->plus($buildCost);
            $buyAllCost = $buyAllCost->plus($buyCost);
            $optimalMixCost = $optimalMixCost->plus(match ($verdict) {
                self::VERDICT_BUILD => $buildCost,
                self::VERDICT_BUY => $buyCost,
                self::VERDICT_UNKNOWN => $buildCost->plus($buyCost),
            });
            if (self::VERDICT_BUILD === $verdict) {
                $buildTypeIds[] = $componentTypeId;
            } elseif (self::VERDICT_BUY === $verdict) {
                $buyTypeIds[] = $componentTypeId;
            }
            $componentMissingData = $buildCost->plus($buyCost);
            $componentsMissingData = $componentsMissingData->plus($componentMissingData);

            $components[] = [
                'typeId' => $componentTypeId,
                'typeName' => $this->typeNameResolver->findName($componentTypeId),
                'quantity' => $quantity,
                'stage' => $this->stageOf($componentRecipe),
                'buildCost' => self::roundedAmountOf($buildCost),
                'buildMaterials' => $this->buildMaterialsOf($componentCost),
                'buildJobInstallCost' => self::roundedAmountOf($componentCost->installCost),
                'buyCostJita' => self::roundedAmountOf($rootLeaf->cost),
                'buyCostStructure' => null === $buyCostStructure ? null : self::roundedAmountOf($buyCostStructure),
                'buyCostJitaCoveragePercent' => PartialPricePresenter::coveragePercentOf($rootLeaf->cost, $componentTypeId),
                'verdict' => $verdict,
                'savings' => null === $savings ? null : round($savings, self::DISPLAYED_DECIMALS),
                'savingsPercent' => null === $savings ? null : self::savingsPercentOf($savings, $buildCost, $buyCost),
                'meUsed' => $efficiency->materialEfficiency->value,
                'runs' => $componentRuns,
                'missingData' => MissingDataPresenter::of($componentMissingData),
                'warnings' => PartialPricePresenter::of($componentMissingData),
            ];
        }

        usort($components, static fn (array $a, array $b): int => match (true) {
            null !== $a['savings'] && null !== $b['savings'] => $b['savings'] <=> $a['savings'],
            null === $a['savings'] && null === $b['savings'] => $a['typeId'] <=> $b['typeId'],
            default => null === $a['savings'] ? 1 : -1,
        });

        $rootCostOutsideComponents = self::rootCostOutsideComponents($rootCost, $componentRecipes);
        $totalProductionCost = $optimalMixCost->plus($rootCostOutsideComponents);
        $sellPrice = $this->sellPriceOf($typeId, $sellStructureId);

        return [
            'typeId' => $typeId,
            'typeName' => $this->typeNameResolver->findName($typeId),
            'isT2' => null !== $rootCost->invention,
            'runs' => $runs,
            'totalProductionCost' => self::roundedAmountOf($totalProductionCost),
            'sellPrice' => null === $sellPrice ? null : round($sellPrice, self::DISPLAYED_DECIMALS),
            'marginPercent' => self::marginPercentOf($sellPrice, $rootCost->producedQuantity->value, $totalProductionCost, $brokerFeeRate + $salesTaxRate),
            'components' => $components,
            'buildAllCost' => self::roundedAmountOf($buildAllCost),
            'buyAllCost' => self::roundedAmountOf($buyAllCost),
            'optimalMixCost' => self::roundedAmountOf($optimalMixCost),
            'buildTypeIds' => $buildTypeIds,
            'buyTypeIds' => $buyTypeIds,
            'missingData' => MissingDataPresenter::of($componentsMissingData->plus($rootCostOutsideComponents)),
            'warnings' => PartialPricePresenter::of($totalProductionCost),
        ];
    }

    /**
     * @return array<int, Recipe> by component typeId, in the order of the product recipe
     */
    private function componentRecipesOf(int $typeId): array
    {
        $recipe = $this->blueprints->recipeFor($typeId)
            ?? throw new \DomainException(\sprintf('Product %d has no blueprint nor reaction: it cannot be built.', $typeId));

        $componentRecipes = [];
        foreach ($recipe->materials as $material) {
            $componentRecipe = $this->blueprints->recipeFor($material->typeId);
            if (null !== $componentRecipe) {
                $componentRecipes[$material->typeId] = $componentRecipe;
            }
        }

        return $componentRecipes;
    }

    /**
     * @param list<int>                 $boughtTypeIds
     * @param list<ProductionStructure> $structures
     */
    private function costOf(int $typeId, Runs $runs, BlueprintEfficiency $efficiency, array $boughtTypeIds, array $structures, FavoriteSystems $favoriteSystems): ProductionCost
    {
        return $this->productionCosts->costOf(new ProductionCostRequest(
            $typeId,
            $runs,
            $efficiency,
            CostMode::Plan,
            $structures,
            $favoriteSystems,
            $boughtTypeIds,
            new InventionSettings(
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                null,
            ),
        ));
    }

    private static function defaultEfficiencyOf(Recipe $recipe): BlueprintEfficiency
    {
        return ActivityKind::Reaction === $recipe->activity
            ? new BlueprintEfficiency(
                new MaterialEfficiency(ProductionPlanner::REACTION_EFFICIENCY_LEVEL),
                new TimeEfficiency(ProductionPlanner::REACTION_EFFICIENCY_LEVEL),
            )
            : new BlueprintEfficiency(
                new MaterialEfficiency(ProductionPlanner::MANUFACTURED_INTERMEDIATE_ME),
                new TimeEfficiency(ProductionPlanner::MANUFACTURED_INTERMEDIATE_TE),
            );
    }

    private function stageOf(Recipe $componentRecipe): string
    {
        if (ActivityKind::Reaction === $componentRecipe->activity) {
            return self::STAGE_REACTION;
        }
        foreach ($componentRecipe->materials as $material) {
            if (ActivityKind::Reaction === $this->blueprints->recipeFor($material->typeId)?->activity) {
                return self::STAGE_T2_COMPONENT;
            }
        }

        return self::STAGE_T1_COMPONENT;
    }

    /**
     * @return list<array{typeId: int, typeName: string|null, quantity: int, unitPrice: float|null, totalPrice: float|null}>
     */
    private function buildMaterialsOf(ProductionCost $componentCost): array
    {
        return array_values(array_map(
            fn (LeafCost $leaf): array => [
                'typeId' => $leaf->typeId,
                'typeName' => $this->typeNameResolver->findName($leaf->typeId),
                'quantity' => (int) $leaf->quantity,
                'unitPrice' => null === $leaf->unitPrice ? null : round($leaf->unitPrice->amount, self::DISPLAYED_DECIMALS),
                'totalPrice' => self::roundedAmountOf($leaf->cost),
            ],
            $componentCost->leaves,
        ));
    }

    /**
     * @return self::VERDICT_*
     */
    private static function verdictOf(Cost $buildCost, Cost $buyCost): string
    {
        return match (true) {
            !$buildCost->isKnown() && !$buyCost->isKnown() => self::VERDICT_UNKNOWN,
            !$buildCost->isKnown() => self::VERDICT_BUY,
            !$buyCost->isKnown() => self::VERDICT_BUILD,
            $buildCost->amount()->equals($buyCost->amount()), $buildCost->amount()->amount < $buyCost->amount()->amount => self::VERDICT_BUILD,
            default => self::VERDICT_BUY,
        };
    }

    private static function savingsPercentOf(float $savings, Cost $buildCost, Cost $buyCost): float
    {
        $highestCost = max($buildCost->amount()->amount, $buyCost->amount()->amount);

        return $highestCost > 0.0 ? round(abs($savings) / $highestCost * self::PERCENT, self::DISPLAYED_DECIMALS) : self::NO_SAVINGS_PERCENT;
    }

    /**
     * @param array<int, Recipe> $componentRecipes
     */
    private static function rootCostOutsideComponents(ProductionCost $rootCost, array $componentRecipes): Cost
    {
        $cost = Cost::known(new Isk(0.0));
        foreach (array_diff_key($rootCost->leaves, $componentRecipes) as $leaf) {
            $cost = $cost->plus($leaf->cost);
        }
        $cost = $cost->plus($rootCost->installCost);

        return null === $rootCost->invention ? $cost : $cost->plus($rootCost->invention->total);
    }

    private function sellPriceOf(int $typeId, ?int $sellStructureId): ?float
    {
        $structureSellPrice = null === $sellStructureId ? null : $this->structureMarketService->getLowestSellPrice($sellStructureId, $typeId);

        return $structureSellPrice ?? $this->jitaMarketService->getPricesWithFallback([$typeId])[$typeId] ?? null;
    }

    private static function marginPercentOf(?float $sellPrice, int $outputQuantity, Cost $totalProductionCost, float $sellOrderFeeRate): ?float
    {
        if (null === $sellPrice || $sellPrice <= 0.0 || !$totalProductionCost->isKnown() || $totalProductionCost->amount()->amount <= 0.0) {
            return null;
        }
        $totalCost = $totalProductionCost->amount()->amount;
        $grossRevenue = $sellPrice * $outputQuantity;
        $netRevenue = $grossRevenue - $grossRevenue * $sellOrderFeeRate;

        return round(($netRevenue - $totalCost) / $totalCost * self::PERCENT, self::DISPLAYED_DECIMALS);
    }

    private static function roundedAmountOf(Cost $cost): ?float
    {
        return $cost->isKnown() ? round($cost->amount()->amount, self::DISPLAYED_DECIMALS) : null;
    }

    private static function idOf(User $user): string
    {
        $userId = $user->getId() ?? throw new \LogicException('A user without id has no configured structure.');

        return $userId->toRfc4122();
    }
}
