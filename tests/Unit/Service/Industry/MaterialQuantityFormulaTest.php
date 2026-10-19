<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\MaterialQuantityFormula;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MaterialQuantityFormula::class)]
final class MaterialQuantityFormulaTest extends TestCase
{
    private const float STRUCTURE_BASE_BONUS = 1.0;
    private const float RIG_BONUS = 4.2;

    public static function quantitiesWithoutStructureNorRig(): iterable
    {
        yield 'ME 0 keeps the base quantity of every run' => [100, 3, 0, 300];
        yield 'ME 10 removes a tenth' => [100, 3, 10, 270];
        yield 'ME 10 rounds a fraction up' => [33, 1, 10, 30];
    }

    #[DataProvider('quantitiesWithoutStructureNorRig')]
    public function testQuantityWithoutStructureNorRigAppliesOnlyTheMe(int $baseQuantity, int $runs, int $meLevel, int $expectedQuantity): void
    {
        $this->assertSame($expectedQuantity, MaterialQuantityFormula::forJob($baseQuantity, $runs, $meLevel, 0.0, 0.0));
    }

    public function testStructureBaseBonusAloneReducesTheQuantity(): void
    {
        $this->assertSame(99, MaterialQuantityFormula::forJob(100, 1, 0, self::STRUCTURE_BASE_BONUS, 0.0));
    }

    public function testRigBonusAloneReducesTheQuantityRoundedUp(): void
    {
        $this->assertSame(96, MaterialQuantityFormula::forJob(100, 1, 0, 0.0, self::RIG_BONUS));
    }

    public function testMeStructureBaseBonusAndRigBonusStackMultiplicatively(): void
    {
        $this->assertSame(2220, MaterialQuantityFormula::forJob(100, 26, 10, self::STRUCTURE_BASE_BONUS, self::RIG_BONUS));
    }

    public static function quantitiesJustAboveAnInteger(): iterable
    {
        yield 'ME 10, 239.00184 rounds to 239.00' => [28, 10, 10, 239];
        yield 'ME 0, 239.00184 rounds to 239.00' => [36, 7, 0, 239];
        yield 'ME 10, 169.008444 rounds to 169.01' => [66, 3, 10, 170];
        yield 'ME 0, 55.00836 rounds to 55.01' => [29, 2, 0, 56];
        yield 'ME 10, 23.046606 rounds to 23.05' => [9, 3, 10, 24];
    }

    #[DataProvider('quantitiesJustAboveAnInteger')]
    public function testQuantityIsRoundedToTwoDecimalsBeforeRoundingUp(int $baseQuantity, int $runs, int $meLevel, int $expectedQuantity): void
    {
        $this->assertSame($expectedQuantity, MaterialQuantityFormula::forJob($baseQuantity, $runs, $meLevel, self::STRUCTURE_BASE_BONUS, self::RIG_BONUS));
    }

    public function testQuantityNeverFallsBelowTheRuns(): void
    {
        $this->assertSame(10, MaterialQuantityFormula::forJob(1, 10, 10, self::STRUCTURE_BASE_BONUS, self::RIG_BONUS));
    }

    public function testQuantityAboveTheRunsIsNotRaisedToTheRuns(): void
    {
        $this->assertSame(9, MaterialQuantityFormula::forJob(10, 1, 10, 0.0, 0.0));
    }
}
