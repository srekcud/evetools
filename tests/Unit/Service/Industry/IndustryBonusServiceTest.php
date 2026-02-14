<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStructureConfig;
use App\Service\Industry\IndustryBonusService;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryBonusService::class)]
class IndustryBonusServiceTest extends TestCase
{
    private IndustryBonusService $bonusService;

    protected function setUp(): void
    {
        $this->bonusService = new IndustryBonusService(
            $this->createStub(IndustryRigCategoryRepository::class),
            $this->createStub(IndustryStructureConfigRepository::class),
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
    }

    private function createStructure(
        string $name,
        string $type,
        string $security,
        array $rigs
    ): IndustryStructureConfig {
        $structure = new IndustryStructureConfig();
        $structure->setName($name);
        $structure->setStructureType($type);
        $structure->setSecurityType($security);
        $structure->setRigs($rigs);

        return $structure;
    }

    // ===========================================
    // Structure Bonus Calculation Tests
    // ===========================================

    public function testEngineeringComplexNullsecWithTier1Rig(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency I']
        );

        // Base 1% + (2.0% × 2.1) = 5.2%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(5.2, $bonus);
    }

    public function testEngineeringComplexNullsecWithTier2Rig(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency II']
        );

        // Base 1% + (2.4% × 2.1) = 6.04%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(6.04, $bonus);
    }

    public function testEngineeringComplexHighsec(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'highsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency II']
        );

        // Base 1% + (2.4% × 1.0) = 3.4%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(3.4, $bonus);
    }

    public function testEngineeringComplexLowsec(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'lowsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency II']
        );

        // Base 1% + (2.4% × 1.9) = 5.56%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(5.56, $bonus);
    }

    public function testRefineryNullsecWithReactorRig(): void
    {
        $structure = $this->createStructure(
            'Test Refinery',
            'refinery',
            'nullsec',
            ['Standup L-Set Reactor Efficiency II']
        );

        // Reactor rigs have different security multipliers (1.1x for nullsec)
        // Refineries have NO base material bonus (only time bonus)
        // Total: 2.4% × 1.1 = 2.64%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(2.64, $bonus);
    }

    public function testRefineryLowsecWithReactorRig(): void
    {
        $structure = $this->createStructure(
            'Test Refinery',
            'refinery',
            'lowsec',
            ['Standup L-Set Reactor Efficiency II']
        );

        // Reactor rigs in lowsec have 1.0x multiplier (same as highsec)
        // Total: 2.4% × 1.0 = 2.4%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(2.4, $bonus);
    }

    public function testRefineryHighsecWithReactorRig(): void
    {
        $structure = $this->createStructure(
            'Test Refinery',
            'refinery',
            'highsec',
            ['Standup L-Set Reactor Efficiency II']
        );

        // Reactor rigs in highsec have 1.0x multiplier
        // Total: 2.4% × 1.0 = 2.4%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(2.4, $bonus);
    }

    public function testRefineryNoBaseForManufacturing(): void
    {
        $structure = $this->createStructure(
            'Test Refinery',
            'refinery',
            'nullsec',
            ['Standup L-Set Reactor Efficiency II']
        );

        // Refinery doesn't give base bonus for manufacturing categories
        // And Reactor rigs don't apply to manufacturing
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(0.0, $bonus);
    }

    public function testEngineeringComplexNoBaseForReactions(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency II']
        );

        // EC doesn't give base bonus for reaction categories
        // And manufacturing rigs don't apply to reactions
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(0.0, $bonus);
    }

    public function testStationNoBonus(): void
    {
        $structure = $this->createStructure(
            'Test Station',
            'station',
            'highsec',
            []
        );

        // Stations have no base bonus and no rigs
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(0.0, $bonus);
    }

    // ===========================================
    // Multiple Rigs Tests
    // ===========================================

    public function testMultipleRigsStack(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            [
                'Standup XL-Set Structure and Component Manufacturing Efficiency I',
                'Standup XL-Set Structure and Component Manufacturing Efficiency II',
            ]
        );

        // Base 1% + ((2.0% + 2.4%) × 2.1) = 1% + 9.24% = 10.24%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(10.24, $bonus);
    }

    public function testRigsOnlyApplyToMatchingCategory(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            ['Standup XL-Set Ship Manufacturing Efficiency II'] // Ships, not components
        );

        // Ship rig doesn't apply to components
        // Only base 1% applies
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(1.0, $bonus);
    }

    // ===========================================
    // calculateAllBonusesForStructure Tests
    // ===========================================

    public function testCalculateAllBonusesForECWithMultipleRigs(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            [
                'Standup XL-Set Structure and Component Manufacturing Efficiency II',
                'Standup XL-Set Ship Manufacturing Efficiency II',
            ]
        );

        $bonuses = $this->bonusService->calculateAllBonusesForStructure($structure);

        // Component categories: 1% base + (2.4% × 2.1) = 6.04%
        $this->assertSame(6.04, $bonuses['basic_capital_component'] ?? 0);
        $this->assertSame(6.04, $bonuses['advanced_component'] ?? 0);

        // Ship categories: 1% base + (2.4% × 2.1) = 6.04%
        $this->assertSame(6.04, $bonuses['capital_ship'] ?? 0);
    }

    public function testCalculateAllBonusesForRefineryWithReactorRig(): void
    {
        $structure = $this->createStructure(
            'Test Refinery',
            'refinery',
            'nullsec',
            ['Standup L-Set Reactor Efficiency II']
        );

        $bonuses = $this->bonusService->calculateAllBonusesForStructure($structure);

        // Reactor rigs: 2.4% × 1.1 (nullsec) = 2.64%
        // Refineries have NO base material bonus
        $this->assertSame(2.64, $bonuses['composite_reaction'] ?? 0);
        $this->assertSame(2.64, $bonuses['biochemical_reaction'] ?? 0);
        $this->assertSame(2.64, $bonuses['hybrid_reaction'] ?? 0);
    }

    // ===========================================
    // Time Bonus Tests
    // ===========================================

    public function testEngineeringComplexTimeBonusBase(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            []
        );

        // EC has 20% base time bonus (no rigs)
        $bonus = $this->bonusService->calculateStructureTimeBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(20.0, $bonus);
    }

    public function testRefineryTimeBonusBase(): void
    {
        $structure = $this->createStructure(
            'Test Refinery',
            'refinery',
            'nullsec',
            []
        );

        // Refinery has 25% base time bonus for reactions (no rigs)
        $bonus = $this->bonusService->calculateStructureTimeBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(25.0, $bonus);
    }

    public function testEngineeringComplexTimeBonusWithEfficiencyRig(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency II']
        );

        // EC base: 20%, Rig time bonus: 24.0% (10x material) × 2.1 = 50.4%
        // Multiplicative stacking: 1 - (1 - 0.20) × (1 - 0.504) = 1 - 0.80 × 0.496 = 1 - 0.3968 = 60.32%
        $bonus = $this->bonusService->calculateStructureTimeBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(60.32, $bonus);
    }

    public function testRefineryTimeBonusWithReactorEfficiencyRig(): void
    {
        $structure = $this->createStructure(
            'Test Refinery',
            'refinery',
            'nullsec',
            ['Standup L-Set Reactor Efficiency II']
        );

        // Refinery base: 25%, Reactor rig time bonus: 24.0% (10x material) × 1.1 = 26.4%
        // Multiplicative stacking: 1 - (1 - 0.25) × (1 - 0.264) = 1 - 0.75 × 0.736 = 1 - 0.552 = 44.8%
        $bonus = $this->bonusService->calculateStructureTimeBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(44.8, $bonus);
    }

    public function testMSetMaterialEfficiencyRigNoTimeBonus(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            ['Standup M-Set Basic Capital Component Manufacturing Material Efficiency II']
        );

        // M-Set "Material Efficiency" rigs do NOT have time bonus
        // Only base 20% applies
        $bonus = $this->bonusService->calculateStructureTimeBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(20.0, $bonus);
    }

    public function testStationNoTimeBonus(): void
    {
        $structure = $this->createStructure(
            'Test Station',
            'station',
            'highsec',
            []
        );

        // Stations have no time bonus
        $bonus = $this->bonusService->calculateStructureTimeBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(0.0, $bonus);
    }

    // ===========================================
    // Adjusted Time Calculation Tests
    // ===========================================

    public function testAdjustedTimeWithNoBonus(): void
    {
        // Base time 3600s (1 hour), TE 0, structure 0%
        $adjusted = $this->bonusService->calculateAdjustedTimePerRun(3600, 0, 0.0);
        $this->assertSame(3600, $adjusted);
    }

    public function testAdjustedTimeWithTE20(): void
    {
        // Base time 3600s, TE 20, structure 0%
        // 3600 × (1 - 20/100) = 3600 × 0.80 = 2880s
        $adjusted = $this->bonusService->calculateAdjustedTimePerRun(3600, 20, 0.0);
        $this->assertSame(2880, $adjusted);
    }

    public function testAdjustedTimeWithStructureBonus(): void
    {
        // Base time 3600s, TE 0, structure 20%
        // 3600 × (1 - 0/100) × (1 - 20/100) = 3600 × 1.0 × 0.80 = 2880s
        $adjusted = $this->bonusService->calculateAdjustedTimePerRun(3600, 0, 20.0);
        $this->assertSame(2880, $adjusted);
    }

    public function testAdjustedTimeWithBothBonuses(): void
    {
        // Base time 3600s, TE 20, structure 20%
        // 3600 × (1 - 20/100) × (1 - 20/100) = 3600 × 0.80 × 0.80 = 2304s
        $adjusted = $this->bonusService->calculateAdjustedTimePerRun(3600, 20, 20.0);
        $this->assertSame(2304, $adjusted);
    }

    public function testAdjustedTimeWithFullBonuses(): void
    {
        // Base time 86400s (1 day), TE 20, structure 25%
        // 86400 × 0.80 × 0.75 = 51840s (0.6 days)
        $adjusted = $this->bonusService->calculateAdjustedTimePerRun(86400, 20, 25.0);
        $this->assertSame(51840, $adjusted);
    }

    public function testAdjustedTimeRoundsUp(): void
    {
        // Base time 3601s, TE 20, structure 0%
        // 3601 × 0.80 = 2880.8 → ceil to 2881
        $adjusted = $this->bonusService->calculateAdjustedTimePerRun(3601, 20, 0.0);
        $this->assertSame(2881, $adjusted);
    }

    public function testDefaultIntermediateTE(): void
    {
        $this->assertSame(20, $this->bonusService->getDefaultIntermediateTE());
    }
}
