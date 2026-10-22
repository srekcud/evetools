<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\User;
use App\Industry\Application\CostMode;
use App\Industry\Application\Decryptors;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\InventionCost;
use App\Industry\Application\InventionSettings;
use App\Industry\Application\JobCost;
use App\Industry\Application\LeafCost;
use App\Industry\Application\ProductionCost;
use App\Industry\Application\ProductionCostCalculator;
use App\Industry\Application\ProductionCostRequest;
use App\Industry\Application\UserProductionStructures;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Decryptor;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\MissingData;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\CachedStructureRepository;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;

class ProfitMarginService
{
    private const float CONTRACT_FEE_RATE = 0.0;

    private const int INVENTION_SKILL_LEVEL_WITHOUT_SKILLS = 0;

    private const float NO_INVENTION_COST = 0.0;

    private const string MARKET_PRICE_REASON = 'market_price';

    public function __construct(
        private readonly ProductionCostCalculator $productionCosts,
        private readonly UserProductionStructures $productionStructures,
        private readonly Decryptors $decryptors,
        private readonly JitaMarketService $jitaMarketService,
        private readonly StructureMarketService $structureMarketService,
        private readonly PublicContractPriceService $publicContractPriceService,
        private readonly TypeNameResolver $typeNameResolver,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly IndustryBlacklistService $blacklistService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function analyze(
        int $typeId,
        int $runs,
        int $meLevel,
        int $teLevel,
        int $sellStructureId,
        int $solarSystemId,
        ?int $decryptorTypeId,
        float $brokerFeeRate,
        float $salesTaxRate,
        ?User $user,
    ): array {
        $decryptors = $this->decryptors->all();
        $costOf = $this->costFunction(
            $typeId,
            $runs,
            new BlueprintEfficiency(new MaterialEfficiency($meLevel), new TimeEfficiency($teLevel)),
            $solarSystemId,
            $user,
        );

        $productionCost = $costOf(self::decryptorOf($decryptors, $decryptorTypeId));
        $outputQuantity = $productionCost->producedQuantity->value;

        $sellPrices = $this->sellPrices($typeId, $outputQuantity, $sellStructureId);
        $marginsOf = fn (Cost $totalCost): array => $this->marginsOf($sellPrices, $outputQuantity, $totalCost, $brokerFeeRate, $salesTaxRate);

        $volumes = $this->jitaMarketService->getAverageDailyVolumes([$typeId]);

        return [
            'typeId' => $typeId,
            'typeName' => $this->typeNameResolver->findName($typeId),
            'isT2' => null !== $productionCost->invention,
            'runs' => $runs,
            'outputQuantity' => $outputQuantity,
            'outputPerRun' => intdiv($outputQuantity, $runs),
            'materialCost' => self::amountOf($productionCost->materialCost),
            'materials' => $this->materialsOf($productionCost),
            'jobInstallCost' => self::amountOf($productionCost->installCost),
            'jobInstallSteps' => $this->jobInstallStepsOf($productionCost),
            'inventionCost' => null === $productionCost->invention ? self::NO_INVENTION_COST : self::amountOf($productionCost->invention->total),
            'totalCost' => self::amountOf($productionCost->totalCost),
            'costPerUnit' => self::amountOf($productionCost->costPerUnit),
            'invention' => null === $productionCost->invention
                ? null
                : $this->inventionDetails($productionCost->invention, $decryptorTypeId, $decryptors, $productionCost, $costOf, $marginsOf),
            'sellPrices' => $sellPrices,
            'brokerFeeRate' => $brokerFeeRate,
            'salesTaxRate' => $salesTaxRate,
            'margins' => $marginsOf($productionCost->totalCost),
            'dailyVolume' => $volumes[$typeId] ?? null,
            'missingData' => MissingDataPresenter::of($productionCost->totalCost),
        ];
    }

    /**
     * @return \Closure(?Decryptor): ProductionCost
     */
    private function costFunction(int $typeId, int $runs, BlueprintEfficiency $efficiency, int $solarSystemId, ?User $user): \Closure
    {
        $structures = null === $user ? [] : $this->productionStructures->configuredBy(self::idOf($user));
        $blacklist = null === $user ? [] : $this->blacklistService->resolveBlacklistedTypeIds($user);
        $favoriteSystems = new FavoriteSystems($solarSystemId, $solarSystemId);

        return fn (?Decryptor $decryptor): ProductionCost => $this->productionCosts->costOf(new ProductionCostRequest(
            $typeId,
            new Runs($runs),
            $efficiency,
            CostMode::MarginalCost,
            $structures,
            $favoriteSystems,
            $blacklist,
            new InventionSettings(
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                self::INVENTION_SKILL_LEVEL_WITHOUT_SKILLS,
                $decryptor,
            ),
        ));
    }

    /**
     * @param list<Decryptor> $decryptors
     */
    private static function decryptorOf(array $decryptors, ?int $decryptorTypeId): ?Decryptor
    {
        if (null === $decryptorTypeId) {
            return null;
        }
        foreach ($decryptors as $decryptor) {
            if ($decryptor->typeId === $decryptorTypeId) {
                return $decryptor;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Type %d is not a decryptor.', $decryptorTypeId));
    }

    private static function idOf(User $user): string
    {
        $userId = $user->getId() ?? throw new \LogicException('A user without id has no configured structure.');

        return $userId->toRfc4122();
    }

    private static function amountOf(Cost $cost): ?float
    {
        return $cost->isKnown() ? $cost->amount()->amount : null;
    }

    /**
     * @return list<array{typeId: int, typeName: ?string, quantity: float, unitPrice: ?float, totalPrice: ?float}>
     */
    private function materialsOf(ProductionCost $productionCost): array
    {
        $names = $this->typeNameResolver->findNames(array_keys($productionCost->leaves));

        return array_values(array_map(
            static fn (LeafCost $leaf): array => [
                'typeId' => $leaf->typeId,
                'typeName' => $names[$leaf->typeId] ?? null,
                'quantity' => $leaf->quantity,
                'unitPrice' => $leaf->unitPrice?->amount,
                'totalPrice' => self::amountOf($leaf->cost),
            ],
            $productionCost->leaves,
        ));
    }

    /**
     * @return list<array{productTypeId: int, productName: ?string, activityType: string, runs: float, installCost: ?float, missingData: list<array{reason: string, typeId: ?int}>}>
     */
    private function jobInstallStepsOf(ProductionCost $productionCost): array
    {
        $names = $this->typeNameResolver->findNames(array_map(static fn (JobCost $job): int => $job->productTypeId, $productionCost->jobs));

        return array_map(
            static fn (JobCost $job): array => [
                'productTypeId' => $job->productTypeId,
                'productName' => $names[$job->productTypeId] ?? null,
                'activityType' => ActivityKind::Reaction === $job->activity ? 'reaction' : 'manufacturing',
                'runs' => $job->runs,
                'installCost' => self::amountOf($job->installCost->total),
                'missingData' => MissingDataPresenter::of($job->installCost->total),
            ],
            $productionCost->jobs,
        );
    }

    /**
     * @param list<Decryptor>                                                                                   $decryptors
     * @param \Closure(?Decryptor): ProductionCost                                                              $costOf
     * @param \Closure(Cost): array<string, array{revenue: float, fees: float, profit: float, margin: ?float}|null> $marginsOf
     *
     * @return array<string, mixed>
     */
    private function inventionDetails(
        InventionCost $invention,
        ?int $selectedDecryptorTypeId,
        array $decryptors,
        ProductionCost $selectedProductionCost,
        \Closure $costOf,
        \Closure $marginsOf,
    ): array {
        $options = [];
        foreach ([null, ...$decryptors] as $decryptor) {
            $optionCost = $decryptor?->typeId === $selectedDecryptorTypeId ? $selectedProductionCost : $costOf($decryptor);
            $optionInvention = $optionCost->invention ?? throw new \LogicException('Every decryptor option of an invented product is invented.');
            $options[] = [
                'decryptorTypeId' => $decryptor?->typeId,
                'decryptorName' => $this->decryptorNameOf($decryptor?->typeId),
                'me' => $optionInvention->outcome->materialEfficiency->value,
                'te' => $optionInvention->outcome->timeEfficiency->value,
                'runs' => $optionInvention->outcome->runs->value,
                'probability' => $optionInvention->chance?->value,
                'inventionCost' => self::amountOf($optionInvention->total),
                'totalProductionCost' => self::amountOf($optionCost->totalCost),
                'bestMargin' => self::bestMarginOf($marginsOf($optionCost->totalCost)),
            ];
        }

        return [
            'baseProbability' => $invention->recipe->baseProbability,
            'unknownReason' => self::inventionUnknownReasonOf($invention),
            'datacores' => array_map(
                fn (RecipeMaterial $datacore): ?string => $this->typeNameResolver->findName($datacore->typeId),
                $invention->recipe->datacores,
            ),
            'selectedDecryptorTypeId' => $selectedDecryptorTypeId,
            'selectedDecryptorName' => $this->decryptorNameOf($selectedDecryptorTypeId),
            'options' => $options,
        ];
    }

    private function decryptorNameOf(?int $decryptorTypeId): ?string
    {
        return null === $decryptorTypeId ? InventionService::NO_DECRYPTOR_NAME : $this->typeNameResolver->findName($decryptorTypeId);
    }

    private static function inventionUnknownReasonOf(InventionCost $invention): ?string
    {
        if (null === $invention->chance) {
            return InventionService::UNKNOWN_REASON_MISSING_PROBABILITY;
        }
        $missingReasons = array_map(static fn (MissingData $missingData): string => $missingData->reason, $invention->total->missingData);

        return \in_array(self::MARKET_PRICE_REASON, $missingReasons, true) ? InventionService::UNKNOWN_REASON_MISSING_MARKET_PRICE : null;
    }

    /**
     * @return array{jitaSell: ?float, structureSell: ?float, structureBuy: ?float, contractSell: ?float, contractCount: ?int, structureId: int, structureName: string}
     */
    private function sellPrices(int $typeId, int $outputQuantity, int $sellStructureId): array
    {
        $jitaSellData = $this->jitaMarketService->getWeightedSellPrice($typeId, $outputQuantity);

        return [
            'jitaSell' => $jitaSellData['weightedPrice'] ?? null,
            'structureSell' => $this->structureMarketService->getLowestSellPrice($sellStructureId, $typeId),
            'structureBuy' => $this->structureMarketService->getHighestBuyPrice($sellStructureId, $typeId),
            'contractSell' => $this->publicContractPriceService->getLowestUnitPrice($typeId),
            'contractCount' => $this->publicContractPriceService->getContractCount($typeId),
            'structureId' => $sellStructureId,
            'structureName' => $this->resolveStructureName($sellStructureId),
        ];
    }

    /**
     * @param array{jitaSell: ?float, structureSell: ?float, structureBuy: ?float, contractSell: ?float} $sellPrices
     *
     * @return array<string, array{revenue: float, fees: float, profit: float, margin: ?float}|null>
     */
    private function marginsOf(array $sellPrices, int $outputQuantity, Cost $totalCost, float $brokerFeeRate, float $salesTaxRate): array
    {
        if (!$totalCost->isKnown()) {
            return ['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null];
        }
        $cost = $totalCost->amount()->amount;
        $sellOrderFeeRate = $brokerFeeRate + $salesTaxRate;

        return [
            'jitaSell' => self::marginForPrice($sellPrices['jitaSell'], $outputQuantity, $cost, $sellOrderFeeRate),
            'structureSell' => self::marginForPrice($sellPrices['structureSell'], $outputQuantity, $cost, $sellOrderFeeRate),
            'structureBuy' => self::marginForPrice($sellPrices['structureBuy'], $outputQuantity, $cost, $salesTaxRate),
            'contractSell' => self::marginForPrice($sellPrices['contractSell'], $outputQuantity, $cost, self::CONTRACT_FEE_RATE),
        ];
    }

    /**
     * @return array{revenue: float, fees: float, profit: float, margin: ?float}|null
     */
    private static function marginForPrice(?float $unitPrice, int $outputQuantity, float $totalCost, float $feeRate): ?array
    {
        if (null === $unitPrice || $unitPrice <= 0.0) {
            return null;
        }

        $grossRevenue = $unitPrice * $outputQuantity;
        $fees = $grossRevenue * $feeRate;
        $profit = $grossRevenue - $fees - $totalCost;

        return [
            'revenue' => $grossRevenue,
            'fees' => $fees,
            'profit' => $profit,
            'margin' => $totalCost > 0 ? ($profit / $totalCost) * 100.0 : null,
        ];
    }

    /**
     * @param array<string, array{revenue: float, fees: float, profit: float, margin: ?float}|null> $venueMargins
     */
    private static function bestMarginOf(array $venueMargins): ?float
    {
        $knownMargins = array_filter(
            array_map(static fn (?array $venueMargin): ?float => $venueMargin['margin'] ?? null, $venueMargins),
            static fn (?float $margin): bool => null !== $margin,
        );

        return [] === $knownMargins ? null : max($knownMargins);
    }

    private function resolveStructureName(int $structureId): string
    {
        $structure = $this->cachedStructureRepository->findByStructureId($structureId);

        return $structure?->getName() ?? "Structure #{$structureId}";
    }
}
