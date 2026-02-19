<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Service\Industry\IndustryCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for EVE Online industry calculation formulas.
 *
 * EVE Material Formula: max(runs, ceil(round(base × runs × (1 - ME/100) × (1 - structureBase/100) × (1 - rigBonus/100), 2)))
 *
 * Structure base bonus and rig bonus are applied multiplicatively, not additively.
 */
class IndustryCalculationTest extends TestCase
{
    private IndustryCalculationService $calculationService;

    protected function setUp(): void
    {
        $this->calculationService = new IndustryCalculationService(
            $this->createMock(\App\Repository\Sde\InvTypeRepository::class),
            $this->createMock(\App\Service\TypeNameResolver::class),
            $this->createMock(\App\Service\Industry\IndustryBonusService::class),
            $this->createMock(\App\Repository\IndustryStructureConfigRepository::class),
            $this->createMock(\App\Repository\IndustryUserSettingsRepository::class),
            $this->createMock(\Doctrine\ORM\EntityManagerInterface::class),
            $this->createMock(\App\Repository\Sde\StaStationRepository::class),
            $this->createMock(\App\Repository\CachedStructureRepository::class),
        );
    }

    /**
     * Calculate material quantity using the real IndustryCalculationService.
     */
    private function calculateMaterial(int $baseQuantity, int $runs, int $me = 0, float $structureBaseBonus = 0.0, float $rigBonus = 0.0): int
    {
        return $this->calculationService->calculateMaterialQuantity($baseQuantity, $runs, $me, $structureBaseBonus, $rigBonus);
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
        // 100 base × 26 runs × 0.99 (1% base) × 0.958 (4.2% rig) = 2465.892 → 2466
        $result = $this->calculateMaterial(100, 26, 0, 1.0, 4.2);
        $this->assertSame(2466, $result);
    }

    public function testMaterialWithME10AndStructureBonus(): void
    {
        // 100 base × 26 runs × 0.9 (ME10) × 0.99 (1% base) × 0.958 (4.2% rig) = 2219.30 → 2220
        $result = $this->calculateMaterial(100, 26, 10, 1.0, 4.2);
        $this->assertSame(2220, $result);
    }

    // ===========================================
    // Rorqual Capital Component Tests (ME 10, base 1%, rig 4.2%)
    // Multiplicative: (1 - 0.01) × (1 - 0.042) = 0.99 × 0.958 = 0.94842
    // ===========================================

    public function testRorqualCapitalCloneVatBay(): void
    {
        // Base: 30, ME 10, structure 1% base + 4.2% rig
        // 30 × 1 × 0.9 × 0.99 × 0.958 = 25.6073... → 26
        $result = $this->calculateMaterial(30, 1, 10, 1.0, 4.2);
        $this->assertSame(26, $result);
    }

    public function testRorqualCapitalConstructionParts(): void
    {
        // Base: 40, ME 10, structure 1% base + 4.2% rig
        // 40 × 1 × 0.9 × 0.99 × 0.958 = 34.1431... → 35
        $result = $this->calculateMaterial(40, 1, 10, 1.0, 4.2);
        $this->assertSame(35, $result);
    }

    public function testRorqualCapitalCapacitorBattery(): void
    {
        // Base: 10, ME 10, structure 1% base + 4.2% rig
        // 10 × 1 × 0.9 × 0.99 × 0.958 = 8.5358... → 9
        $result = $this->calculateMaterial(10, 1, 10, 1.0, 4.2);
        $this->assertSame(9, $result);
    }

    public function testRorqualSmallComponentsMinimum(): void
    {
        // Base: 5, ME 10, structure 1% base + 4.2% rig
        // 5 × 1 × 0.9 × 0.99 × 0.958 = 4.2679... → 5 (min is runs=1)
        $result = $this->calculateMaterial(5, 1, 10, 1.0, 4.2);
        $this->assertSame(5, $result);
    }

    // ===========================================
    // Reaction Bonus Tests (refinery: 0% base, 2.64% rig = 2.4% × 1.1 nullsec)
    // ===========================================

    public function testReactionCarbonFiberInput(): void
    {
        // RCF reaction: 200 CF base per run, no ME, 0% base + 2.64% rig
        // 200 × 152 runs × 1.0 × 1.0 × 0.9736 = 29597.44 → 29598
        $result = $this->calculateMaterial(200, 152, 0, 0.0, 2.64);
        $this->assertSame(29598, $result);
    }

    public function testReactionThermosettingPolymerInput(): void
    {
        // Same as CF: 200 base × 152 runs × 0.9736 = 29598
        $result = $this->calculateMaterial(200, 152, 0, 0.0, 2.64);
        $this->assertSame(29598, $result);
    }

    public function testReactionOxyOrganicSolventsInput(): void
    {
        // 1 base × 152 runs × 0.9736 = 147.99 → 152 (min is runs)
        $result = $this->calculateMaterial(1, 152, 0, 0.0, 2.64);
        $this->assertSame(152, $result); // min(runs) applies
    }

    // ===========================================
    // Multiplicative vs Additive Bonus Tests
    // ===========================================

    public function testMultiplicativeBonusSotiyoT1NullsecGeneticSafeguardFilter(): void
    {
        // Genetic Safeguard Filter: base 75, ME10
        // Sotiyo T1 rig in nullsec: base 1%, rig 2.0% × 2.1 = 4.2%
        // OLD (wrong, additive): (1 - 5.2/100) = 0.948 → ceil(75 × 0.90 × 0.948) = ceil(63.99) = 64
        // NEW (correct, multiplicative): 0.99 × 0.958 = 0.94842 → ceil(75 × 0.90 × 0.99 × 0.958) = ceil(64.02) = 65
        $result = $this->calculateMaterial(75, 1, 10, 1.0, 4.2);
        $this->assertSame(65, $result);
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
        // 1 base × 10 runs × 0.9 × 0.99 × 0.958 = 8.536 → 9, but min is 10
        $result = $this->calculateMaterial(1, 10, 10, 1.0, 4.2);
        $this->assertSame(10, $result);
    }

    public function testRoundingPrecision(): void
    {
        // Test that rounding to 2 decimals before ceil works correctly
        // 100 × 26 × 0.9 × 0.99 × 0.958 = 2219.3028
        // round(2219.3028, 2) = 2219.30
        // ceil(2219.30) = 2220
        $result = $this->calculateMaterial(100, 26, 10, 1.0, 4.2);
        $this->assertSame(2220, $result);
    }

    public function testNoNegativeValues(): void
    {
        // ME and structure bonus should never produce negative results
        $result = $this->calculateMaterial(1, 1, 10, 5.0, 5.0);
        $this->assertGreaterThan(0, $result);
    }

    // ===========================================
    // Full Chain Test (Component → RCF → CF)
    // ===========================================

    public function testFullChainCapitalCloneVatBay(): void
    {
        // Step 1: CCVB needs RCF (ME 10, EC base 1% + rig 4.2%)
        // Base RCF: 100 per run, 26 runs
        // 100 × 26 × 0.9 × 0.99 × 0.958 = 2219.30 → 2220
        $rcfNeeded = $this->calculateMaterial(100, 26, 10, 1.0, 4.2);
        $this->assertSame(2220, $rcfNeeded);

        // Step 2: RCF runs
        $rcfRuns = $this->calculateRuns($rcfNeeded, 200);
        $this->assertSame(12, $rcfRuns); // ceil(2220/200) = 12

        // Step 3: RCF reaction needs CF (0 ME, refinery 0% base + 2.64% rig)
        $cfNeeded = $this->calculateMaterial(200, $rcfRuns, 0, 0.0, 2.64);
        $this->assertSame(2337, $cfNeeded);

        // Step 4: CF runs
        $cfRuns = $this->calculateRuns($cfNeeded, 200);
        $this->assertSame(12, $cfRuns); // ceil(2337/200) = 12
    }
}
