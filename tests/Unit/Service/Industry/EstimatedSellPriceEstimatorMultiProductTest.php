<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectStep;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\User;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Service\Industry\EstimatedSellPrice;
use App\Service\Industry\EstimatedSellPriceEstimator;
use App\Service\Industry\SalesFees\SalesFees;
use App\Service\Industry\SalesFees\SellerSalesFeesResolver;
use App\Service\JitaMarketService;
use App\Service\StructureMarketService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(EstimatedSellPriceEstimator::class)]
#[Group('issue-179')]
final class EstimatedSellPriceEstimatorMultiProductTest extends TestCase
{
    private const int RIFTER = 587;
    private const int HAMMERHEAD_I = 2185;
    private const int ACOLYTE_I = 2203;
    private const int SABRE = 22456;
    private const int FERNITE_CARBIDE = 16673;
    private const int PROJECT_RUNS = 10;
    private const int PREFERRED_MARKET_STRUCTURE_ID = 1035466617946;
    private const float BROKER_FEE_RATE = 0.01;
    private const float SALES_TAX_RATE = 0.02;
    private const float RIFTER_JITA_PRICE = 100_000.0;
    private const float HAMMERHEAD_I_JITA_PRICE = 20_000.0;
    private const float HAMMERHEAD_I_LOWER_JITA_PRICE = 15_000.0;
    private const float HAMMERHEAD_I_STRUCTURE_PRICE = 20_000.0;
    private const float ACOLYTE_I_JITA_PRICE = 9_000.0;
    private const float SABRE_JITA_PRICE = 4_000_000.0;
    private const float FERNITE_CARBIDE_JITA_PRICE = 30.0;
    private const int HAMMERHEAD_I_QUANTITY = 5;
    private const int FERNITE_CARBIDE_QUANTITY = 20_000;

    public function testSecondProductOfTheProjectAddsItsBestPriceTimesTheQuantityOfItsRootStep(): void
    {
        $project = $this->projectWithRootSteps($this->rootStep(self::HAMMERHEAD_I, 'manufacturing', self::HAMMERHEAD_I_QUANTITY));

        $estimatedSellPrice = $this->estimatorOnJitaPrices([
            self::RIFTER => self::RIFTER_JITA_PRICE,
            self::HAMMERHEAD_I => self::HAMMERHEAD_I_JITA_PRICE,
        ])->estimateFor($project);

        $this->assertEstimate(1_100_000.0, 'jita', 33_000.0, $estimatedSellPrice);
    }

    public function testEstimatedTaxIsUnknownWhileTheEstimatedSellPriceIsComputedWhenTheSellerSalesFeesAreUnknown(): void
    {
        $project = $this->projectWithRootSteps($this->rootStep(self::HAMMERHEAD_I, 'manufacturing', self::HAMMERHEAD_I_QUANTITY));

        $estimatedSellPrice = $this->estimatorOnJitaPrices([
            self::RIFTER => self::RIFTER_JITA_PRICE,
            self::HAMMERHEAD_I => self::HAMMERHEAD_I_JITA_PRICE,
        ], null)->estimateFor($project);

        self::assertNotNull($estimatedSellPrice);
        self::assertEqualsWithDelta(1_100_000.0, $estimatedSellPrice->amount, 0.001);
        self::assertSame('jita', $estimatedSellPrice->source);
        self::assertNull($estimatedSellPrice->taxAmount);
    }

    public function testSecondProductPricedAtTheStructureAboveJitaMakesTheSourceMixed(): void
    {
        $project = $this->projectOfUserPreferringAStructureWithRootSteps($this->rootStep(self::HAMMERHEAD_I, 'manufacturing', self::HAMMERHEAD_I_QUANTITY));

        $estimatedSellPrice = $this->estimatorOn(
            [self::RIFTER => self::RIFTER_JITA_PRICE, self::HAMMERHEAD_I => self::HAMMERHEAD_I_LOWER_JITA_PRICE],
            [self::HAMMERHEAD_I => self::HAMMERHEAD_I_STRUCTURE_PRICE],
        )->estimateFor($project);

        $this->assertEstimate(1_100_000.0, 'mixed', 33_000.0, $estimatedSellPrice);
    }

    public function testEveryProductPricedAtTheStructureKeepsTheStructureSource(): void
    {
        $project = $this->projectOfUserPreferringAStructureWithRootSteps($this->rootStep(self::HAMMERHEAD_I, 'manufacturing', self::HAMMERHEAD_I_QUANTITY));

        $estimatedSellPrice = $this->estimatorOn(
            [self::RIFTER => 80_000.0, self::HAMMERHEAD_I => self::HAMMERHEAD_I_LOWER_JITA_PRICE],
            [self::RIFTER => self::RIFTER_JITA_PRICE, self::HAMMERHEAD_I => self::HAMMERHEAD_I_STRUCTURE_PRICE],
        )->estimateFor($project);

        $this->assertEstimate(1_100_000.0, 'structure', 33_000.0, $estimatedSellPrice);
    }

    public function testSecondProductWithoutAnyPriceMakesTheWholeEstimatedSellPriceUnknown(): void
    {
        $project = $this->projectOfUserPreferringAStructureWithRootSteps($this->rootStep(self::HAMMERHEAD_I, 'manufacturing', self::HAMMERHEAD_I_QUANTITY));

        $estimatedSellPrice = $this->estimatorOn([self::RIFTER => self::RIFTER_JITA_PRICE], [])->estimateFor($project);

        self::assertNull($estimatedSellPrice);
    }

    public function testSecondProductWhoseJobsAreSplitCountsTheQuantityOfEveryJob(): void
    {
        $project = $this->projectWithRootSteps(
            $this->rootStep(self::HAMMERHEAD_I, 'manufacturing', 3),
            $this->rootStep(self::HAMMERHEAD_I, 'manufacturing', 2),
        );

        $estimatedSellPrice = $this->estimatorOnJitaPrices([
            self::RIFTER => self::RIFTER_JITA_PRICE,
            self::HAMMERHEAD_I => self::HAMMERHEAD_I_JITA_PRICE,
        ])->estimateFor($project);

        $this->assertEstimate(1_100_000.0, 'jita', 33_000.0, $estimatedSellPrice);
    }

    public function testSecondProductOfAReactionRootStepIsCounted(): void
    {
        $project = $this->projectWithRootSteps($this->rootStep(self::FERNITE_CARBIDE, 'reaction', self::FERNITE_CARBIDE_QUANTITY));

        $estimatedSellPrice = $this->estimatorOnJitaPrices([
            self::RIFTER => self::RIFTER_JITA_PRICE,
            self::FERNITE_CARBIDE => self::FERNITE_CARBIDE_JITA_PRICE,
        ])->estimateFor($project);

        $this->assertEstimate(1_600_000.0, 'jita', 48_000.0, $estimatedSellPrice);
    }

    public function testRootCopyAndInventionStepsOfOtherTypesAreNotProducts(): void
    {
        $project = $this->projectWithRootSteps(
            $this->rootStep(self::ACOLYTE_I, 'copy', 4),
            $this->rootStep(self::SABRE, 'invention', 2),
        );

        $estimatedSellPrice = $this->estimatorOnJitaPrices([
            self::RIFTER => self::RIFTER_JITA_PRICE,
            self::ACOLYTE_I => self::ACOLYTE_I_JITA_PRICE,
            self::SABRE => self::SABRE_JITA_PRICE,
        ])->estimateFor($project);

        $this->assertEstimate(1_000_000.0, 'jita', 30_000.0, $estimatedSellPrice);
    }

    public function testRootStepOfTheProjectProductAndStepsBelowTheRootAreNotCountedAgain(): void
    {
        $project = $this->projectWithRootSteps(
            $this->rootStep(self::RIFTER, 'manufacturing', 7),
            $this->rootStep(self::HAMMERHEAD_I, 'manufacturing', 6, depth: 1),
        );

        $estimatedSellPrice = $this->estimatorOnJitaPrices([
            self::RIFTER => self::RIFTER_JITA_PRICE,
            self::HAMMERHEAD_I => self::HAMMERHEAD_I_JITA_PRICE,
        ])->estimateFor($project);

        $this->assertEstimate(1_000_000.0, 'jita', 30_000.0, $estimatedSellPrice);
    }

    private function assertEstimate(float $amount, string $source, float $taxAmount, ?EstimatedSellPrice $estimatedSellPrice): void
    {
        self::assertNotNull($estimatedSellPrice);
        self::assertEqualsWithDelta($amount, $estimatedSellPrice->amount, 0.001);
        self::assertSame($source, $estimatedSellPrice->source);
        self::assertEqualsWithDelta($taxAmount, $estimatedSellPrice->taxAmount, 0.001);
    }

    private function projectWithRootSteps(IndustryProjectStep ...$steps): IndustryProject
    {
        return $this->projectOfUserWithRootSteps(new User(), ...$steps);
    }

    private function projectOfUserPreferringAStructureWithRootSteps(IndustryProjectStep ...$steps): IndustryProject
    {
        $user = $this->createStub(User::class);
        $user->method('getPreferredMarketStructureId')->willReturn(self::PREFERRED_MARKET_STRUCTURE_ID);

        return $this->projectOfUserWithRootSteps($user, ...$steps);
    }

    private function projectOfUserWithRootSteps(User $user, IndustryProjectStep ...$steps): IndustryProject
    {
        $project = (new IndustryProject())->setOwner($user)
            ->setProductTypeId(self::RIFTER)
            ->setRuns(self::PROJECT_RUNS);
        foreach ($steps as $step) {
            $project->addStep($step);
        }

        return $project;
    }

    private function rootStep(int $productTypeId, string $activityType, int $quantity, int $depth = 0): IndustryProjectStep
    {
        return (new IndustryProjectStep())
            ->setBlueprintTypeId($productTypeId + 1_000_000)
            ->setProductTypeId($productTypeId)
            ->setActivityType($activityType)
            ->setQuantity($quantity)
            ->setRuns($quantity)
            ->setDepth($depth);
    }

    private function estimatorOnJitaPrices(array $jitaPrices, ?SalesFees $salesFees = new SalesFees(self::BROKER_FEE_RATE, self::SALES_TAX_RATE)): EstimatedSellPriceEstimator
    {
        return $this->estimatorOn($jitaPrices, [], $salesFees);
    }

    private function estimatorOn(array $jitaPrices, array $structurePrices, ?SalesFees $salesFees = new SalesFees(self::BROKER_FEE_RATE, self::SALES_TAX_RATE)): EstimatedSellPriceEstimator
    {
        $jitaMarketService = $this->createStub(JitaMarketService::class);
        $jitaMarketService->method('getPrice')->willReturnCallback(
            static fn (int $typeId): ?float => $jitaPrices[$typeId] ?? null,
        );
        $structureMarketService = $this->createStub(StructureMarketService::class);
        $structureMarketService->method('getLowestSellPrice')->willReturnCallback(
            static fn (int $structureId, int $typeId): ?float => $structurePrices[$typeId] ?? null,
        );
        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('findBlueprintForProduct')->willReturn((new IndustryActivityProduct())->setQuantity(1));
        $salesFeesResolver = $this->createStub(SellerSalesFeesResolver::class);
        $salesFeesResolver->method('forUser')->willReturn($salesFees);

        return new EstimatedSellPriceEstimator($jitaMarketService, $structureMarketService, $activityProductRepository, $salesFeesResolver);
    }
}
