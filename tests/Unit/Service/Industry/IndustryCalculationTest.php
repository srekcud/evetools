<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use PHPUnit\Framework\TestCase;

/**
 * Tests for EVE Online industry calculation formulas.
 *
 * EVE Material Formula: max(runs, ceil(round(base × runs × (1 - ME/100) × (1 - structureBonus/100), 2)))
 */
class IndustryCalculationTest extends TestCase
{
    /**
     * Calculate material quantity using EVE formula.
     */
    private function calculateMaterial(int $baseQuantity, int $runs, int $me = 0, float $structureBonus = 0.0): int
    {
        $meMultiplier = $me > 0 ? (1 - $me / 100) : 1.0;
        $structureMultiplier = $structureBonus > 0 ? (1 - $structureBonus / 100) : 1.0;

        return max(
            $runs,
            (int) ceil(round($baseQuantity * $runs * $meMultiplier * $structureMultiplier, 2))
        );
    }

    /**
     * Calculate runs needed to produce a quantity.
     */
    private function calculateRuns(int $quantityNeeded, int $outputPerRun): int
    {
        return (int) ceil($quantityNeeded / $outputPerRun);
    }

    // ===========================================
    // Basic Material Calculation Tests
    // ===========================================

    public function testMaterialWithoutAnyBonus(): void
    {
        // 100 base × 26 runs × no bonus = 2600
        $result = $this->calculateMaterial(100, 26, 0, 0.0);
        $this->assertSame(2600, $result);
    }

    public function testMaterialWithME10Only(): void
    {
        // 100 base × 26 runs × 0.9 (ME10) = 2340
        $result = $this->calculateMaterial(100, 26, 10, 0.0);
        $this->assertSame(2340, $result);
    }

    public function testMaterialWithStructureBonusOnly(): void
    {
        // 100 base × 26 runs × 0.948 (5.2% struct) = 2464.8 → 2465
        $result = $this->calculateMaterial(100, 26, 0, 5.2);
        $this->assertSame(2465, $result);
    }

    public function testMaterialWithME10AndStructureBonus(): void
    {
        // 100 base × 26 runs × 0.9 (ME10) × 0.948 (5.2%) = 2218.32 → 2219
        $result = $this->calculateMaterial(100, 26, 10, 5.2);
        $this->assertSame(2219, $result);
    }

    // ===========================================
    // Rorqual Capital Component Tests (ME 10 + 5.2%)
    // ===========================================

    public function testRorqualCapitalCloneVatBay(): void
    {
        // Base: 30, ME 10, 5.2% structure
        // 30 × 1 × 0.9 × 0.948 = 25.596 → 26
        $result = $this->calculateMaterial(30, 1, 10, 5.2);
        $this->assertSame(26, $result);
    }

    public function testRorqualCapitalConstructionParts(): void
    {
        // Base: 40, ME 10, 5.2% structure
        // 40 × 1 × 0.9 × 0.948 = 34.128 → 35
        $result = $this->calculateMaterial(40, 1, 10, 5.2);
        $this->assertSame(35, $result);
    }

    public function testRorqualCapitalCapacitorBattery(): void
    {
        // Base: 10, ME 10, 5.2% structure
        // 10 × 1 × 0.9 × 0.948 = 8.532 → 9
        $result = $this->calculateMaterial(10, 1, 10, 5.2);
        $this->assertSame(9, $result);
    }

    public function testRorqualSmallComponentsMinimum(): void
    {
        // Base: 5, ME 10, 5.2% structure
        // 5 × 1 × 0.9 × 0.948 = 4.266 → 5 (min is runs=1)
        $result = $this->calculateMaterial(5, 1, 10, 5.2);
        $this->assertSame(5, $result);
    }

    // ===========================================
    // Reaction Bonus Tests (2.64% refinery = 2.4% × 1.1 nullsec)
    // ===========================================

    public function testReactionCarbonFiberInput(): void
    {
        // RCF reaction: 200 CF base per run, no ME, 2.64% refinery bonus
        // 200 × 152 runs × 1.0 × 0.9736 = 29597.44 → 29598
        $result = $this->calculateMaterial(200, 152, 0, 2.64);
        $this->assertSame(29598, $result);
    }

    public function testReactionThermosettingPolymerInput(): void
    {
        // Same as CF: 200 base × 152 runs × 0.9736 = 29598
        $result = $this->calculateMaterial(200, 152, 0, 2.64);
        $this->assertSame(29598, $result);
    }

    public function testReactionOxyOrganicSolventsInput(): void
    {
        // 1 base × 152 runs × 0.9736 = 147.99 → 152 (min is runs)
        $result = $this->calculateMaterial(1, 152, 0, 2.64);
        $this->assertSame(152, $result); // min(runs) applies
    }

    // ===========================================
    // Structure Bonus Calculation Tests
    // ===========================================

    public function testEngineeringComplexNullsecTier1(): void
    {
        // EC base: 1%
        // Tier 1 rig: 2.0%
        // Nullsec multiplier: 2.1
        // Total: 1 + (2.0 × 2.1) = 5.2%
        $baseBonus = 1.0;
        $rigBonus = 2.0;
        $secMultiplier = 2.1;
        $total = $baseBonus + ($rigBonus * $secMultiplier);

        $this->assertSame(5.2, $total);
    }

    public function testEngineeringComplexNullsecTier2(): void
    {
        // EC base: 1%
        // Tier 2 rig: 2.4%
        // Nullsec: 2.1 (for manufacturing rigs)
        // Total: 1 + (2.4 × 2.1) = 6.04%
        $baseBonus = 1.0;
        $rigBonus = 2.4;
        $secMultiplier = 2.1;
        $total = $baseBonus + ($rigBonus * $secMultiplier);

        $this->assertSame(6.04, $total);
    }

    public function testRefineryNullsecTier2(): void
    {
        // Refinery: NO base material bonus (only time bonus)
        // L-Set Reactor II: 2.4%
        // Nullsec: 1.1 (reactor rigs have different multiplier!)
        // Total: 2.4 × 1.1 = 2.64%
        $baseBonus = 0.0;
        $rigBonus = 2.4;
        $secMultiplier = 1.1;
        $total = $baseBonus + ($rigBonus * $secMultiplier);

        $this->assertSame(2.64, $total);
    }

    public function testHighsecSecurityMultiplier(): void
    {
        // Highsec: 1.0
        // Tier 2 rig: 2.4%
        // Total rig bonus: 2.4 × 1.0 = 2.4%
        $rigBonus = 2.4;
        $secMultiplier = 1.0;
        $total = $rigBonus * $secMultiplier;

        $this->assertSame(2.4, $total);
    }

    public function testLowsecSecurityMultiplierManufacturing(): void
    {
        // Lowsec manufacturing: 1.9
        // Tier 2 rig: 2.4%
        // Total rig bonus: 2.4 × 1.9 = 4.56%
        $rigBonus = 2.4;
        $secMultiplier = 1.9;
        $total = $rigBonus * $secMultiplier;

        $this->assertSame(4.56, $total);
    }

    public function testLowsecSecurityMultiplierReactions(): void
    {
        // Lowsec reactions: 1.0 (same as highsec)
        // Tier 2 reactor rig: 2.4%
        // Total rig bonus: 2.4 × 1.0 = 2.4%
        $rigBonus = 2.4;
        $secMultiplier = 1.0;
        $total = $rigBonus * $secMultiplier;

        $this->assertSame(2.4, $total);
    }

    // ===========================================
    // Runs Calculation Tests
    // ===========================================

    public function testRunsCalculationExact(): void
    {
        // 30400 quantity / 200 per run = 152 runs
        $result = $this->calculateRuns(30400, 200);
        $this->assertSame(152, $result);
    }

    public function testRunsCalculationRoundUp(): void
    {
        // 28564 quantity / 200 per run = 142.82 → 143 runs
        $result = $this->calculateRuns(28564, 200);
        $this->assertSame(143, $result);
    }

    public function testRunsCalculationSmall(): void
    {
        // 2219 quantity / 200 per run = 11.095 → 12 runs
        $result = $this->calculateRuns(2219, 200);
        $this->assertSame(12, $result);
    }

    public function testRunsCalculationOutputThree(): void
    {
        // Construction components output 3 per run
        // 171 quantity / 3 per run = 57 runs
        $result = $this->calculateRuns(171, 3);
        $this->assertSame(57, $result);
    }

    // ===========================================
    // Edge Cases
    // ===========================================

    public function testMinimumIsRuns(): void
    {
        // When calculated value is less than runs, use runs
        // 1 base × 10 runs × 0.9 × 0.948 = 8.532 → 9, but min is 10
        $result = $this->calculateMaterial(1, 10, 10, 5.2);
        $this->assertSame(10, $result);
    }

    public function testRoundingPrecision(): void
    {
        // Test that rounding to 2 decimals before ceil works correctly
        // 100 × 26 × 0.9 × 0.948 = 2218.32
        // round(2218.32, 2) = 2218.32
        // ceil(2218.32) = 2219
        $result = $this->calculateMaterial(100, 26, 10, 5.2);
        $this->assertSame(2219, $result);
    }

    public function testNoNegativeValues(): void
    {
        // ME and structure bonus should never produce negative results
        $result = $this->calculateMaterial(1, 1, 10, 10.0);
        $this->assertGreaterThan(0, $result);
    }

    // ===========================================
    // Full Chain Test (Component → RCF → CF)
    // ===========================================

    public function testFullChainCapitalCloneVatBay(): void
    {
        // Step 1: CCVB needs RCF (ME 10 + 5.2% EC)
        // Base RCF: 100 per run, 26 runs
        $rcfNeeded = $this->calculateMaterial(100, 26, 10, 5.2);
        $this->assertSame(2219, $rcfNeeded);

        // Step 2: RCF runs
        $rcfRuns = $this->calculateRuns($rcfNeeded, 200);
        $this->assertSame(12, $rcfRuns); // ceil(2219/200) = 12

        // Step 3: RCF reaction needs CF (0 ME + 2.64% refinery = 2.4% × 1.1 nullsec)
        $cfNeeded = $this->calculateMaterial(200, $rcfRuns, 0, 2.64);
        $this->assertSame(2337, $cfNeeded);

        // Step 4: CF runs
        $cfRuns = $this->calculateRuns($cfNeeded, 200);
        $this->assertSame(12, $cfRuns); // ceil(2337/200) = 12
    }
}
