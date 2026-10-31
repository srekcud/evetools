<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryProjectStep;
use App\Entity\IndustryStepPurchase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryProjectStep::class)]
#[Group('issue-182')]
final class IndustryProjectStepInStockTest extends TestCase
{
    private const int FERNITE_ALLOY = 16656;
    private const int HYDROGEN_FUEL_BLOCK = 4246;
    private const array UNIT_PRICE_BY_TYPE_ID = [
        self::FERNITE_ALLOY => 1_450.0,
        self::HYDROGEN_FUEL_BLOCK => 18_500.0,
    ];

    public static function stepCoverageProvider(): iterable
    {
        yield 'E1 quantity 100, stock 40, nothing purchased' => [100, 40, [], false, 0, false];
        yield 'E2 quantity 0, no stock, nothing purchased' => [0, 0, [], false, 0, false];
        yield 'E3 quantity 100, no stock, 100 of the product purchased' => [100, 0, [[self::FERNITE_ALLOY, 100]], false, 100, true];
        yield 'E4 quantity 100, no stock, 60 of the product purchased' => [100, 0, [[self::FERNITE_ALLOY, 60]], false, 60, false];
        yield 'E5 quantity 100, stock 100, 50 of the product purchased' => [100, 100, [[self::FERNITE_ALLOY, 50]], false, 50, true];
        yield 'E6 quantity 100, stock 70, 50 of the product purchased' => [100, 70, [[self::FERNITE_ALLOY, 50]], false, 50, false];
        yield 'E7 quantity 100, no stock, 500 of a material purchased' => [100, 0, [[self::HYDROGEN_FUEL_BLOCK, 500]], false, 0, false];
        yield 'E8 quantity 100, no stock, marked purchased' => [100, 0, [], true, 0, false];
        yield 'E9 quantity 100, stock 30, 60 and 40 of the product purchased' => [100, 30, [[self::FERNITE_ALLOY, 60], [self::FERNITE_ALLOY, 40]], false, 100, true];
        yield 'E10 quantity 0, stock 5, nothing purchased' => [0, 5, [], false, 0, true];
        yield 'E11 quantity 0, no stock, 10 of the product purchased' => [0, 0, [[self::FERNITE_ALLOY, 10]], false, 10, true];
    }

    #[DataProvider('stepCoverageProvider')]
    public function testStepIsInStockOnlyWhenTheLargerOfItsStockAndPurchasedQuantityIsPositiveAndReachesItsQuantity(
        int $quantity,
        int $inStockQuantity,
        array $purchases,
        bool $markedPurchased,
        int $expectedPurchasedQuantity,
        bool $expectedInStock,
    ): void {
        $step = $this->ferniteAlloyStep($quantity, $inStockQuantity, $purchases, $markedPurchased);

        self::assertSame($expectedInStock, $step->isInStock());
    }

    #[DataProvider('stepCoverageProvider')]
    public function testPurchasedQuantityOfAStepCountsOnlyThePurchasesOfItsProduct(
        int $quantity,
        int $inStockQuantity,
        array $purchases,
        bool $markedPurchased,
        int $expectedPurchasedQuantity,
        bool $expectedInStock,
    ): void {
        $step = $this->ferniteAlloyStep($quantity, $inStockQuantity, $purchases, $markedPurchased);

        self::assertSame($expectedPurchasedQuantity, $step->purchasedQuantityOfProduct());
    }

    public function testNegativeStockIsStoredAsZero(): void
    {
        $step = (new IndustryProjectStep())->setInStockQuantity(-5);

        self::assertSame(0, $step->getInStockQuantity());
    }

    public function testStepOfNegativeStockAndQuantityZeroIsNotInStock(): void
    {
        $step = $this->ferniteAlloyStep(0, -5, [], false);

        self::assertFalse($step->isInStock());
    }

    public function testStockOfAStepIsComparedToItsQuantityNotToItsRuns(): void
    {
        $stepWithStockOfTwoHundred = $this->ferniteAlloyStep(400, 200, [], false)->setRuns(2);
        $stepWithStockOfFourHundred = $this->ferniteAlloyStep(400, 400, [], false)->setRuns(2);

        self::assertSame(
            [false, true],
            [$stepWithStockOfTwoHundred->isInStock(), $stepWithStockOfFourHundred->isInStock()],
        );
    }

    public function testPurchasedQuantityOfAStepIgnoresAMaterialPurchasedAlongsideItsProduct(): void
    {
        $step = $this->ferniteAlloyStep(
            100,
            0,
            [[self::FERNITE_ALLOY, 60], [self::HYDROGEN_FUEL_BLOCK, 500]],
            false,
        );

        self::assertSame([60, false], [$step->purchasedQuantityOfProduct(), $step->isInStock()]);
    }

    public function testStepWhosePurchasedQuantityExceedsItsQuantityIsInStock(): void
    {
        $step = $this->ferniteAlloyStep(100, 0, [[self::FERNITE_ALLOY, 150]], false);

        self::assertSame([150, true], [$step->purchasedQuantityOfProduct(), $step->isInStock()]);
    }

    public function testPurchaseOnOneJobOfASplitGroupLeavesTheOtherJobOutOfStock(): void
    {
        $firstJob = $this->ferniteAlloyStep(200, 0, [[self::FERNITE_ALLOY, 200]], false)
            ->setRuns(1)
            ->setSplitGroupId('fernite-alloy-split')
            ->setSplitIndex(0)
            ->setTotalGroupRuns(2);
        $secondJob = $this->ferniteAlloyStep(200, 0, [], false)
            ->setRuns(1)
            ->setSplitGroupId('fernite-alloy-split')
            ->setSplitIndex(1)
            ->setTotalGroupRuns(2);

        self::assertSame(
            [[true, 200], [false, 0]],
            [
                [$firstJob->isInStock(), $firstJob->purchasedQuantityOfProduct()],
                [$secondJob->isInStock(), $secondJob->purchasedQuantityOfProduct()],
            ],
        );
    }

    private function ferniteAlloyStep(int $quantity, int $inStockQuantity, array $purchases, bool $markedPurchased): IndustryProjectStep
    {
        $step = (new IndustryProjectStep())
            ->setProductTypeId(self::FERNITE_ALLOY)
            ->setActivityType('reaction')
            ->setQuantity($quantity)
            ->setInStockQuantity($inStockQuantity)
            ->setPurchased($markedPurchased);
        foreach ($purchases as [$typeId, $purchasedQuantity]) {
            $step->addPurchase($this->purchaseOf($typeId, $purchasedQuantity));
        }

        return $step;
    }

    private function purchaseOf(int $typeId, int $quantity): IndustryStepPurchase
    {
        $unitPrice = self::UNIT_PRICE_BY_TYPE_ID[$typeId];

        return (new IndustryStepPurchase())
            ->setTypeId($typeId)
            ->setQuantity($quantity)
            ->setUnitPrice($unitPrice)
            ->setTotalPrice($quantity * $unitPrice)
            ->setSource('manual');
    }
}
