<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\Character;
use App\Entity\User;
use App\Industry\Application\CostMode;
use App\Industry\Application\Decryptors;
use App\Industry\Application\FavoriteSystems;
use App\Industry\Application\InventionCost;
use App\Industry\Application\InventionSkillLevels;
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
use App\Industry\Domain\OrderBookCoverage;
use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\CachedStructureRepository;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use App\Service\TypeNameResolver;

class ProfitMarginService
{
    private const float CONTRACT_FEE_RATE = 0.0;

    private const float NO_INVENTION_COST = 0.0;

    private const string UNKNOWN_REASON_MISSING_INVENTOR_SKILLS = 'missing_inventor_skills';

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
        private readonly InventorSkills $inventorSkills,
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
        ?SalesFees $salesFees,
        ?User $user,
        int $reactionSolarSystemId,
        ?Character $inventor = null,
    ): array {
        $decryptors = $this->decryptors->all();
        $skillLevels = $this->inventorSkills->levelsFor($typeId, $inventor);
        $costOf = $this->costFunction(
            $typeId,
            $runs,
            new BlueprintEfficiency(new MaterialEfficiency($meLevel), new TimeEfficiency($teLevel)),
            $solarSystemId,
            $reactionSolarSystemId,
            $user,
            $skillLevels,
        );

        $productionCost = $costOf(self::decryptorOf($decryptors, $decryptorTypeId));
        $outputQuantity = $productionCost->producedQuantity->value;

        $sellPrices = $this->sellPrices($typeId, $outputQuantity, $sellStructureId);
        $marginsOf = fn (Cost $totalCost): array => $this->marginsOf($sellPrices, $outputQuantity, $totalCost, $salesFees);

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
                : $this->inventionDetails($productionCost->invention, $runs, $decryptorTypeId, $decryptors, $productionCost, $costOf, $marginsOf, $inventor, $skillLevels),
            'sellPrices' => $sellPrices,
            'brokerFeeRate' => $salesFees?->brokerFeeRate,
            'salesTaxRate' => $salesFees?->salesTaxRate,
            'margins' => $marginsOf($productionCost->totalCost),
            'dailyVolume' => $volumes[$typeId] ?? null,
            'missingData' => MissingDataPresenter::of($productionCost->totalCost),
            'warnings' => PartialPricePresenter::of($productionCost->totalCost),
        ];
    }

    /**
     * @return \Closure(?Decryptor): ProductionCost
     */
    private function costFunction(int $typeId, int $runs, BlueprintEfficiency $efficiency, int $solarSystemId, int $reactionSolarSystemId, ?User $user, InventionSkillLevels $skillLevels): \Closure
    {
        $structures = null === $user ? [] : $this->productionStructures->configuredBy(self::idOf($user));
        $blacklist = null === $user ? [] : $this->blacklistService->resolveBlacklistedTypeIds($user);
        $favoriteSystems = new FavoriteSystems($solarSystemId, $reactionSolarSystemId);

        return fn (?Decryptor $decryptor): ProductionCost => $this->productionCosts->costOf(new ProductionCostRequest(
            $typeId,
            new Runs($runs),
            $efficiency,
            CostMode::MarginalCost,
            $structures,
            $favoriteSystems,
            $blacklist,
            $skillLevels->settingsWith($decryptor),
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
     * @param \Closure(Cost): array<string, array{revenue: float, fees: ?float, profit: ?float, margin: ?float}|null> $marginsOf
     *
     * @return array<string, mixed>
     */
    private function inventionDetails(
        InventionCost $invention,
        int $requestedRuns,
        ?int $selectedDecryptorTypeId,
        array $decryptors,
        ProductionCost $selectedProductionCost,
        \Closure $costOf,
        \Closure $marginsOf,
        ?Character $inventor,
        InventionSkillLevels $skillLevels,
    ): array {
        $options = [];
        $costPerUnitWithoutDecryptor = null;
        foreach ([null, ...$decryptors] as $decryptor) {
            $optionCost = $decryptor?->typeId === $selectedDecryptorTypeId ? $selectedProductionCost : $costOf($decryptor);
            $optionInvention = $optionCost->invention ?? throw new \LogicException('Every decryptor option of an invented product is invented.');
            $costPerUnit = self::amountOf($optionCost->costPerUnit);
            if (null === $decryptor) {
                $costPerUnitWithoutDecryptor = $costPerUnit;
            }
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
                'expectedAttempts' => $optionInvention->expectedAttempts,
                'manufacturingJobs' => (int) ceil($requestedRuns / $optionInvention->outcome->runs->value),
                'decryptorCost' => self::amountOf($optionInvention->decryptorCost),
                'costPerUnit' => $costPerUnit,
                'costPerUnitDelta' => null === $costPerUnit || null === $costPerUnitWithoutDecryptor ? null : $costPerUnit - $costPerUnitWithoutDecryptor,
            ];
        }

        return [
            'baseProbability' => $invention->recipe->baseProbability,
            'unknownReason' => self::inventionUnknownReasonOf($invention),
            'datacores' => array_map(
                fn (RecipeMaterial $datacore): ?string => $this->typeNameResolver->findName($datacore->typeId),
                $invention->recipe->datacores,
            ),
            'datacoresCostPerAttempt' => self::amountOf($invention->datacoresCost),
            'inventor' => null === $inventor ? null : [
                'characterId' => $inventor->getId()?->toRfc4122() ?? throw new \LogicException('An inventor without id cannot be identified.'),
                'characterName' => $inventor->getName(),
                'skillsKnown' => $skillLevels->isKnown(),
            ],
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
        if (null === $invention->recipe->baseProbability) {
            return InventionService::UNKNOWN_REASON_MISSING_PROBABILITY;
        }
        if (null === $invention->chance) {
            return self::UNKNOWN_REASON_MISSING_INVENTOR_SKILLS;
        }
        $missingReasons = array_map(static fn (MissingData $missingData): string => $missingData->reason, $invention->total->missingData);

        return \in_array(self::MARKET_PRICE_REASON, $missingReasons, true) ? InventionService::UNKNOWN_REASON_MISSING_MARKET_PRICE : null;
    }

    /**
     * @return array{jitaSell: ?float, jitaSellCoveragePercent: ?float, structureSell: ?float, structureBuy: ?float, contractSell: ?float, contractCount: ?int, structureId: int, structureName: string}
     */
    private function sellPrices(int $typeId, int $outputQuantity, int $sellStructureId): array
    {
        $jitaSellData = $this->jitaMarketService->getWeightedSellPrice($typeId, $outputQuantity);
        $jitaSellCoverage = $this->jitaSellCoverageOf($jitaSellData);

        return [
            'jitaSell' => null === $jitaSellCoverage || null === $jitaSellData ? null : $jitaSellData['weightedPrice'],
            'jitaSellCoveragePercent' => null !== $jitaSellCoverage && $jitaSellCoverage->isPartial() ? PartialPricePresenter::coveragePercentOfCoverage($jitaSellCoverage) : null,
            'structureSell' => $this->structureMarketService->getLowestSellPrice($sellStructureId, $typeId),
            'structureBuy' => $this->structureMarketService->getHighestBuyPrice($sellStructureId, $typeId),
            'contractSell' => $this->publicContractPriceService->getLowestUnitPrice($typeId),
            'contractCount' => $this->publicContractPriceService->getContractCount($typeId),
            'structureId' => $sellStructureId,
            'structureName' => $this->resolveStructureName($sellStructureId),
        ];
    }

    /**
     * @param array{weightedPrice: float, coverage?: float, ordersUsed: int}|null $jitaSellData
     */
    private function jitaSellCoverageOf(?array $jitaSellData): ?OrderBookCoverage
    {
        $fraction = $jitaSellData['coverage'] ?? null;

        return null !== $fraction && $fraction > 0.0 ? new OrderBookCoverage($fraction) : null;
    }

    /**
     * @param array{jitaSell: ?float, structureSell: ?float, structureBuy: ?float, contractSell: ?float} $sellPrices
     *
     * @return array<string, array{revenue: float, fees: ?float, profit: ?float, margin: ?float}|null>
     */
    private function marginsOf(array $sellPrices, int $outputQuantity, Cost $totalCost, ?SalesFees $salesFees): array
    {
        if (!$totalCost->isKnown()) {
            return ['jitaSell' => null, 'structureSell' => null, 'structureBuy' => null, 'contractSell' => null];
        }
        $cost = $totalCost->amount()->amount;
        $sellOrderFeeRate = null === $salesFees ? null : $salesFees->brokerFeeRate + $salesFees->salesTaxRate;
        $buyOrderFeeRate = $salesFees?->salesTaxRate;

        return [
            'jitaSell' => self::marginForPrice($sellPrices['jitaSell'], $outputQuantity, $cost, $sellOrderFeeRate),
            'structureSell' => self::marginForPrice($sellPrices['structureSell'], $outputQuantity, $cost, $sellOrderFeeRate),
            'structureBuy' => self::marginForPrice($sellPrices['structureBuy'], $outputQuantity, $cost, $buyOrderFeeRate),
            'contractSell' => self::marginForPrice($sellPrices['contractSell'], $outputQuantity, $cost, self::CONTRACT_FEE_RATE),
        ];
    }

    /**
     * @return array{revenue: float, fees: ?float, profit: ?float, margin: ?float}|null
     */
    private static function marginForPrice(?float $unitPrice, int $outputQuantity, float $totalCost, ?float $feeRate): ?array
    {
        if (null === $unitPrice || $unitPrice <= 0.0) {
            return null;
        }

        $grossRevenue = $unitPrice * $outputQuantity;
        if (null === $feeRate) {
            return ['revenue' => $grossRevenue, 'fees' => null, 'profit' => null, 'margin' => null];
        }

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
     * @param array<string, array{revenue: float, fees: ?float, profit: ?float, margin: ?float}|null> $venueMargins
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
