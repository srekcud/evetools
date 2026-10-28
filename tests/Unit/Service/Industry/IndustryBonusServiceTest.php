<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStructureConfig;
use App\Entity\Sde\IndustryActivityProduct;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Entity\User;
use App\Enum\IndustryActivityType;
use App\Service\Industry\IndustryBonusService;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(IndustryBonusService::class)]
class IndustryBonusServiceTest extends TestCase
{
    private const int REACTION_PRODUCT_TYPE_ID = 16673;
    private const int REACTION_PRODUCT_GROUP_ID = 429;
    private const int REACTION_FORMULA_TYPE_ID = 46166;
    private const int REACTION_FORMULA_GROUP_ID = 1888;

    private const array MANUFACTURING_CATEGORIES = [
        'basic_small_ship',
        'basic_medium_ship',
        'basic_large_ship',
        'advanced_small_ship',
        'advanced_medium_ship',
        'advanced_large_ship',
        'capital_ship',
        'basic_capital_component',
        'advanced_component',
        'equipment',
        'ammunition',
        'drone',
        'fighter',
        'structure',
        'structure_component',
    ];

    private const array M_SET_RIG_CATEGORIES = [
        'Basic Small Ship' => ['basic_small_ship'],
        'Basic Medium Ship' => ['basic_medium_ship'],
        'Basic Large Ship' => ['basic_large_ship'],
        'Advanced Small Ship' => ['advanced_small_ship'],
        'Advanced Medium Ship' => ['advanced_medium_ship'],
        'Advanced Large Ship' => ['advanced_large_ship'],
        'Basic Capital Component' => ['basic_capital_component'],
        'Advanced Component' => ['advanced_component'],
        'Equipment' => ['equipment'],
        'Ammunition' => ['ammunition'],
        'Drone and Fighter' => ['drone', 'fighter'],
        'Structure' => ['structure', 'structure_component'],
    ];

    private const array L_SET_RIG_CATEGORIES = [
        'Basic Small Ship' => ['basic_small_ship'],
        'Basic Medium Ship' => ['basic_medium_ship'],
        'Basic Large Ship' => ['basic_large_ship'],
        'Advanced Small Ship' => ['advanced_small_ship'],
        'Advanced Medium Ship' => ['advanced_medium_ship'],
        'Advanced Large Ship' => ['advanced_large_ship'],
        'Capital Ship' => ['capital_ship'],
        'Basic Capital Component' => ['basic_capital_component'],
        'Advanced Component' => ['advanced_component'],
        'Equipment' => ['equipment'],
        'Ammunition' => ['ammunition'],
        'Drone and Fighter' => ['drone', 'fighter'],
        'Structure' => ['structure', 'structure_component'],
    ];

    private const array XL_SET_RIG_CATEGORIES = [
        'Ship' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship'],
        'Equipment and Consumable' => ['equipment', 'ammunition', 'drone', 'fighter'],
        'Structure and Component' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component'],
    ];

    private const array REACTOR_RIG_CATEGORIES = [
        'Composite' => 'composite_reaction',
        'Biochemical' => 'biochemical_reaction',
        'Hybrid' => 'hybrid_reaction',
    ];

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

        // Base 1%, Rig (2.0% × 2.1) = 4.2%
        // Multiplicative: 1 - (1 - 0.01) × (1 - 0.042) = 1 - 0.99 × 0.958 = 1 - 0.94842 = 5.158%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(1.0, $bonus['base']);
        $this->assertSame(4.2, $bonus['rig']);
        $this->assertSame(5.16, $bonus['total']);
    }

    public function testEngineeringComplexNullsecWithTier2Rig(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'nullsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency II']
        );

        // Base 1%, Rig (2.4% × 2.1) = 5.04%
        // Multiplicative: 1 - (1 - 0.01) × (1 - 0.0504) = 1 - 0.99 × 0.9496 = 1 - 0.940104 = 5.9896%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(1.0, $bonus['base']);
        $this->assertSame(5.04, $bonus['rig']);
        $this->assertSame(5.99, $bonus['total']);
    }

    public function testEngineeringComplexHighsec(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'highsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency II']
        );

        // Base 1%, Rig (2.4% × 1.0) = 2.4%
        // Multiplicative: 1 - (1 - 0.01) × (1 - 0.024) = 1 - 0.99 × 0.976 = 1 - 0.96624 = 3.376%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(1.0, $bonus['base']);
        $this->assertSame(2.4, $bonus['rig']);
        $this->assertSame(3.38, $bonus['total']);
    }

    public function testEngineeringComplexLowsec(): void
    {
        $structure = $this->createStructure(
            'Test EC',
            'engineering_complex',
            'lowsec',
            ['Standup XL-Set Structure and Component Manufacturing Efficiency II']
        );

        // Base 1%, Rig (2.4% × 1.9) = 4.56%
        // Multiplicative: 1 - (1 - 0.01) × (1 - 0.0456) = 1 - 0.99 × 0.9544 = 1 - 0.944856 = 5.5144%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(1.0, $bonus['base']);
        $this->assertSame(4.56, $bonus['rig']);
        $this->assertSame(5.51, $bonus['total']);
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
        // Rig: 2.4% × 1.1 = 2.64%, base: 0%
        // Total = 2.64% (no base to multiply with)
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(0.0, $bonus['base']);
        $this->assertSame(2.64, $bonus['rig']);
        $this->assertSame(2.64, $bonus['total']);
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
        // Rig: 2.4% × 1.0 = 2.4%, base: 0%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(0.0, $bonus['base']);
        $this->assertSame(2.4, $bonus['rig']);
        $this->assertSame(2.4, $bonus['total']);
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
        // Rig: 2.4% × 1.0 = 2.4%, base: 0%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'composite_reaction');
        $this->assertSame(0.0, $bonus['base']);
        $this->assertSame(2.4, $bonus['rig']);
        $this->assertSame(2.4, $bonus['total']);
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
        $this->assertSame(0.0, $bonus['base']);
        $this->assertSame(0.0, $bonus['rig']);
        $this->assertSame(0.0, $bonus['total']);
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
        $this->assertSame(0.0, $bonus['base']);
        $this->assertSame(0.0, $bonus['rig']);
        $this->assertSame(0.0, $bonus['total']);
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
        $this->assertSame(0.0, $bonus['base']);
        $this->assertSame(0.0, $bonus['rig']);
        $this->assertSame(0.0, $bonus['total']);
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

        // Base 1%, Rigs ((2.0% + 2.4%) × 2.1) = 9.24%
        // Multiplicative: 1 - (1 - 0.01) × (1 - 0.0924) = 1 - 0.99 × 0.9076 = 1 - 0.898524 = 10.1476%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(1.0, $bonus['base']);
        $this->assertSame(9.24, $bonus['rig']);
        $this->assertSame(10.15, $bonus['total']);
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
        // Only base 1% applies, rig = 0%
        $bonus = $this->bonusService->calculateStructureBonusForCategory($structure, 'basic_capital_component');
        $this->assertSame(1.0, $bonus['base']);
        $this->assertSame(0.0, $bonus['rig']);
        $this->assertSame(1.0, $bonus['total']);
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

    // ===========================================
    // Issue #71: reaction time bonus of the refineries (SDE strReactionTimeMultiplier)
    // Athanor: no reaction time bonus. Tatara: 0.75, i.e. 25 %.
    // ===========================================

    public function testAthanorHasNoReactionTimeBonus(): void
    {
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'nullsec', []);

        $this->assertSame(0.0, $this->bonusService->calculateStructureTimeBonusForCategory($athanor, 'composite_reaction'));
        $this->assertSame(0.0, $this->bonusService->getBaseTimeBonus($athanor, true));
    }

    public function testTataraReactionTimeBonusIsTwentyFivePercent(): void
    {
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'nullsec', []);

        $this->assertSame(25.0, $this->bonusService->calculateStructureTimeBonusForCategory($tatara, 'composite_reaction'));
        $this->assertSame(25.0, $this->bonusService->getBaseTimeBonus($tatara, true));
    }

    public function testAthanorReactionTimeBonusComesFromTheReactorRigOnly(): void
    {
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'nullsec', ['Standup L-Set Reactor Efficiency II']);

        // No structure base, rig 24 % x 1.1 (reaction rig, nullsec) = 26.4 %
        $this->assertSame(26.4, $this->bonusService->calculateStructureTimeBonusForCategory($athanor, 'composite_reaction'));
    }

    public function testTataraReactionTimeBonusStacksWithTheReactorRig(): void
    {
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'nullsec', ['Standup L-Set Reactor Efficiency II']);

        // 1 - 0.75 x (1 - 0.264) = 44.8 %
        $this->assertSame(44.8, $this->bonusService->calculateStructureTimeBonusForCategory($tatara, 'composite_reaction'));
    }

    public function testBestReactionTimeStructureIsTheTataraOverTheAthanor(): void
    {
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'nullsec', []);
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'nullsec', []);
        $bonusService = $this->bonusServiceForStructures([$athanor, $tatara]);

        $best = $bonusService->findBestStructureForCategoryTimeBonus(new User(), 'composite_reaction', true);

        $this->assertSame($tatara, $best['structure']);
        $this->assertSame(25.0, $best['bonus']);
    }

    public function testAthanorRemainsTheReactionStructureForAReactionWithoutRigCategory(): void
    {
        // Guard: losing its time bonus must not make the Athanor unusable for reactions
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'nullsec', []);
        $bonusService = $this->bonusServiceForStructures([$athanor]);

        $best = $bonusService->findBestStructureForProduct(new User(), 16670, true);

        $this->assertSame($athanor, $best['structure']);
        $this->assertNull($best['category']);
    }

    // ===========================================
    // Issue #71: Thukker rigs (SDE: attributeEngRigMatBonus 2.0, attributeThukkerEngRigMatBonus 3.7
    // for capital components, attributeEngRigTimeBonus 20, security modifiers x0.1 / x1.9 / x0.1)
    // ===========================================

    /** @return iterable<string, array{string, string, string, float, float}> */
    public static function thukkerRigMaterialBonusProvider(): iterable
    {
        $mSetBasicCapital = 'Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency';
        $lSetBasicCapital = 'Standup L-Set Thukker Basic Capital Component Manufacturing Efficiency';
        $mSetAdvanced = 'Standup M-Set Thukker Advanced Component Manufacturing Material Efficiency';
        $lSetAdvanced = 'Standup L-Set Thukker Advanced Component Manufacturing Efficiency';
        $xlSet = 'Standup XL-Set Thukker Structure and Component Manufacturing Efficiency';

        // [rig, security, category, rig bonus, total with the Raitaru 1 % base: 1 - 0.99 x (1 - rig)]
        yield 'M-Set basic capital, highsec: 3.7 x 0.1' => [$mSetBasicCapital, 'highsec', 'basic_capital_component', 0.37, 1.37];
        yield 'M-Set basic capital, lowsec: 3.7 x 1.9' => [$mSetBasicCapital, 'lowsec', 'basic_capital_component', 7.03, 7.96];
        yield 'M-Set basic capital, nullsec: 3.7 x 0.1' => [$mSetBasicCapital, 'nullsec', 'basic_capital_component', 0.37, 1.37];
        yield 'L-Set basic capital, lowsec: 3.7 x 1.9' => [$lSetBasicCapital, 'lowsec', 'basic_capital_component', 7.03, 7.96];
        yield 'L-Set basic capital, nullsec: 3.7 x 0.1' => [$lSetBasicCapital, 'nullsec', 'basic_capital_component', 0.37, 1.37];
        yield 'XL-Set on basic capital component, lowsec: 3.7 x 1.9' => [$xlSet, 'lowsec', 'basic_capital_component', 7.03, 7.96];
        yield 'XL-Set on structure component, highsec: 2.0 x 0.1' => [$xlSet, 'highsec', 'structure_component', 0.2, 1.2];
        yield 'XL-Set on structure component, lowsec: 2.0 x 1.9' => [$xlSet, 'lowsec', 'structure_component', 3.8, 4.76];
        yield 'XL-Set on structure component, nullsec: 2.0 x 0.1' => [$xlSet, 'nullsec', 'structure_component', 0.2, 1.2];
        yield 'XL-Set on structure, lowsec: 2.0 x 1.9' => [$xlSet, 'lowsec', 'structure', 3.8, 4.76];
        yield 'XL-Set on advanced component, lowsec: 2.0 x 1.9, not 3.7' => [$xlSet, 'lowsec', 'advanced_component', 3.8, 4.76];
        yield 'M-Set advanced component, highsec: 2.0 x 0.1' => [$mSetAdvanced, 'highsec', 'advanced_component', 0.2, 1.2];
        yield 'M-Set advanced component, lowsec: 2.0 x 1.9' => [$mSetAdvanced, 'lowsec', 'advanced_component', 3.8, 4.76];
        yield 'L-Set advanced component, nullsec: 2.0 x 0.1' => [$lSetAdvanced, 'nullsec', 'advanced_component', 0.2, 1.2];
    }

    #[DataProvider('thukkerRigMaterialBonusProvider')]
    public function testThukkerRigMaterialBonusUsesTheThukkerSecurityModifiers(
        string $thukkerRig,
        string $securityType,
        string $category,
        float $expectedRigBonus,
        float $expectedTotalBonus,
    ): void {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', $securityType, [$thukkerRig]);

        $bonus = $this->bonusService->calculateStructureBonusForCategory($raitaru, $category);

        $this->assertSame(1.0, $bonus['base']);
        $this->assertSame($expectedRigBonus, $bonus['rig']);
        $this->assertSame($expectedTotalBonus, $bonus['total']);
    }

    /** @return iterable<string, array{string, float}> */
    public static function thukkerRigTimeBonusProvider(): iterable
    {
        // Sotiyo 30 % base, rig 20 % x security modifier: 1 - 0.70 x (1 - rig)
        yield 'highsec: 20 x 0.1 = 2 %' => ['highsec', 31.4];
        yield 'lowsec: 20 x 1.9 = 38 %' => ['lowsec', 56.6];
        yield 'nullsec: 20 x 0.1 = 2 %' => ['nullsec', 31.4];
    }

    #[DataProvider('thukkerRigTimeBonusProvider')]
    public function testThukkerRigTimeBonusUsesTheThukkerSecurityModifiers(string $securityType, float $expectedTimeBonus): void
    {
        $sotiyo = $this->createStructure(
            'Test Sotiyo',
            'sotiyo',
            $securityType,
            ['Standup XL-Set Thukker Structure and Component Manufacturing Efficiency'],
        );

        $this->assertSame($expectedTimeBonus, $this->bonusService->calculateStructureTimeBonusForCategory($sotiyo, 'structure_component'));
    }

    public static function thukkerXlSetRigTimeBonusCategoryProvider(): iterable
    {
        yield 'structure: 1 - 0.70 x (1 - 0.38)' => ['structure', 56.6];
        yield 'basic capital component: 1 - 0.70 x (1 - 0.38)' => ['basic_capital_component', 56.6];
        yield 'advanced component: 1 - 0.70 x (1 - 0.38)' => ['advanced_component', 56.6];
    }

    #[DataProvider('thukkerXlSetRigTimeBonusCategoryProvider')]
    public function testThukkerXlSetRigTimeBonusOnALowsecSotiyoAppliesToEachTargetedCategory(string $category, float $expectedTimeBonus): void
    {
        $sotiyo = $this->createStructure(
            'Test Sotiyo',
            'sotiyo',
            'lowsec',
            ['Standup XL-Set Thukker Structure and Component Manufacturing Efficiency'],
        );

        $this->assertSame($expectedTimeBonus, $this->bonusService->calculateStructureTimeBonusForCategory($sotiyo, $category));
    }

    public static function thukkerLSetRigProvider(): iterable
    {
        yield 'basic capital component rig: 1 - 0.80 x (1 - 0.38)' => ['Standup L-Set Thukker Basic Capital Component Manufacturing Efficiency', 'basic_capital_component', 50.4];
        yield 'advanced component rig: 1 - 0.80 x (1 - 0.38)' => ['Standup L-Set Thukker Advanced Component Manufacturing Efficiency', 'advanced_component', 50.4];
    }

    #[DataProvider('thukkerLSetRigProvider')]
    public function testThukkerLSetRigTimeBonusOnAnAzbel(string $thukkerRig, string $category, float $expectedTimeBonus): void
    {
        $azbel = $this->createStructure('Test Azbel', 'azbel', 'lowsec', [$thukkerRig]);

        $this->assertSame($expectedTimeBonus, $this->bonusService->calculateStructureTimeBonusForCategory($azbel, $category));
    }

    public function testThukkerMSetMaterialEfficiencyRigHasNoTimeBonus(): void
    {
        $raitaru = $this->createStructure(
            'Test Raitaru',
            'raitaru',
            'lowsec',
            ['Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency'],
        );

        $this->assertSame(15.0, $this->bonusService->calculateStructureTimeBonusForCategory($raitaru, 'basic_capital_component'));
    }

    /** @return iterable<string, array{string, string, string, float}> */
    public static function standardRigMaterialBonusProvider(): iterable
    {
        // Guard: the non-Thukker rigs keep 2.0 / 2.4 % with x1.0 / x1.9 / x2.1
        yield 'M-Set basic capital T2, highsec' => ['Standup M-Set Basic Capital Component Manufacturing Material Efficiency II', 'highsec', 'basic_capital_component', 2.4];
        yield 'M-Set basic capital T2, lowsec' => ['Standup M-Set Basic Capital Component Manufacturing Material Efficiency II', 'lowsec', 'basic_capital_component', 4.56];
        yield 'M-Set basic capital T2, nullsec' => ['Standup M-Set Basic Capital Component Manufacturing Material Efficiency II', 'nullsec', 'basic_capital_component', 5.04];
        yield 'L-Set advanced component T1, nullsec' => ['Standup L-Set Advanced Component Manufacturing Efficiency I', 'nullsec', 'advanced_component', 4.2];
        yield 'XL-Set structure and component T2, lowsec' => ['Standup XL-Set Structure and Component Manufacturing Efficiency II', 'lowsec', 'structure_component', 4.56];
        yield 'XL-Set structure and component T1, highsec' => ['Standup XL-Set Structure and Component Manufacturing Efficiency I', 'highsec', 'basic_capital_component', 2.0];
    }

    #[DataProvider('standardRigMaterialBonusProvider')]
    public function testStandardRigMaterialBonusIsUnchanged(
        string $rig,
        string $securityType,
        string $category,
        float $expectedRigBonus,
    ): void {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', $securityType, [$rig]);

        $this->assertSame($expectedRigBonus, $this->bonusService->calculateStructureBonusForCategory($raitaru, $category)['rig']);
    }

    public function testMaterialBonusSkipsANonMatchingRigAndStillCountsTheNextOne(): void
    {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'nullsec', [
            'Standup M-Set Basic Large Ship Manufacturing Material Efficiency II',
            'Standup M-Set Basic Capital Component Manufacturing Material Efficiency II',
        ]);

        // Only the capital component rig applies: 2.4 x 2.1 = 5.04 ; 1 - 0.99 x 0.9496 = 5.99
        $this->assertSame(
            ['total' => 5.99, 'base' => 1.0, 'rig' => 5.04],
            $this->bonusService->calculateStructureBonusForCategory($raitaru, 'basic_capital_component'),
        );
    }

    public function testTimeBonusAddsUpTheTimeRigsOfTheSameCategory(): void
    {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'highsec', [
            'Standup M-Set Basic Capital Component Manufacturing Time Efficiency I',
            'Standup M-Set Basic Capital Component Manufacturing Time Efficiency II',
        ]);

        // Rigs 20 + 24 = 44 % (x1.0 highsec) ; 1 - 0.85 x 0.56 = 52.4 %
        $this->assertSame(52.4, $this->bonusService->calculateStructureTimeBonusForCategory($raitaru, 'basic_capital_component'));
    }

    // ===========================================
    // getBaseTimeBonus: the base applies only to the activity of the structure
    // ===========================================

    public function testAthanorBaseTimeBonusIsZeroForManufacturingAndForReactions(): void
    {
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'nullsec', []);

        $this->assertSame(0.0, $this->bonusService->getBaseTimeBonus($athanor, false));
        $this->assertSame(0.0, $this->bonusService->getBaseTimeBonus($athanor, true));
    }

    public function testTataraBaseTimeBonusDoesNotApplyToManufacturing(): void
    {
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'nullsec', []);

        $this->assertSame(0.0, $this->bonusService->getBaseTimeBonus($tatara, false));
    }

    public function testRaitaruBaseTimeBonusAppliesToManufacturingOnly(): void
    {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'nullsec', []);

        $this->assertSame(15.0, $this->bonusService->getBaseTimeBonus($raitaru, false));
        $this->assertSame(0.0, $this->bonusService->getBaseTimeBonus($raitaru, true));
    }

    // ===========================================
    // Best structure on base bonuses only (product without rig category)
    // ===========================================

    public function testBaseOnlyWithoutAnyStructureReturnsNoStructureAndZeroBonus(): void
    {
        $best = $this->bonusServiceForStructures([])->findBestStructureForProduct(new User(), 16670, false);

        $this->assertSame(
            ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null],
            $best,
        );
    }

    public function testBaseOnlyManufacturingNeverRetainsAStationWithoutBaseBonus(): void
    {
        $station = $this->createStructure('Test Station', 'station', 'highsec', []);

        $best = $this->bonusServiceForStructures([$station])->findBestStructureForProduct(new User(), 16670, false);

        $this->assertSame(
            ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null],
            $best,
        );
    }

    public function testBaseOnlyManufacturingRetainsTheEngineeringComplexWithTheBestBaseTimeBonus(): void
    {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'nullsec', []);
        $sotiyo = $this->createStructure('Test Sotiyo', 'sotiyo', 'nullsec', []);
        $azbel = $this->createStructure('Test Azbel', 'azbel', 'nullsec', []);

        $best = $this->bonusServiceForStructures([$raitaru, $sotiyo, $azbel])->findBestStructureForProduct(new User(), 16670, false);

        // Sotiyo 30 % > Azbel 20 % > Raitaru 15 % ; Engineering Complex base material bonus 1 %
        $this->assertSame($sotiyo, $best['structure']);
        $this->assertSame(['total' => 1.0, 'base' => 1.0, 'rig' => 0.0], $best['bonus']);
        $this->assertNull($best['category']);
    }

    public function testBaseOnlyReactionWithTheAthanorAloneRetainsItWithoutMaterialBonus(): void
    {
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'nullsec', []);
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'nullsec', []);

        $best = $this->bonusServiceForStructures([$raitaru, $athanor])->findBestStructureForProduct(new User(), 16670, true);

        $this->assertSame($athanor, $best['structure']);
        $this->assertSame(['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], $best['bonus']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function athanorAndTataraOrderProvider(): iterable
    {
        yield 'Athanor first' => [['athanor', 'tatara']];
        yield 'Tatara first' => [['tatara', 'athanor']];
    }

    /** @param list<string> $structureTypes */
    #[DataProvider('athanorAndTataraOrderProvider')]
    public function testBaseOnlyReactionPrefersTheTataraOverTheAthanor(array $structureTypes): void
    {
        $structures = array_map(
            fn (string $structureType) => $this->createStructure('Test '.$structureType, $structureType, 'nullsec', []),
            $structureTypes,
        );

        $best = $this->bonusServiceForStructures($structures)->findBestStructureForProduct(new User(), 16670, true);

        $this->assertSame('tatara', $best['structure']->getStructureType());
        $this->assertSame(['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], $best['bonus']);
    }

    // ===========================================
    // Best structure on time bonus for a category
    // ===========================================

    public function testBestTimeStructureWithoutAnyStructureKeepsTheCategory(): void
    {
        $best = $this->bonusServiceForStructures([])->findBestStructureForCategoryTimeBonus(new User(), 'basic_capital_component');

        $this->assertSame(['structure' => null, 'bonus' => 0.0, 'category' => 'basic_capital_component'], $best);
    }

    public function testBestTimeStructureDefaultsToManufacturing(): void
    {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'nullsec', []);
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'nullsec', []);

        $best = $this->bonusServiceForStructures([$tatara, $raitaru])->findBestStructureForCategoryTimeBonus(new User(), 'basic_capital_component');

        $this->assertSame(['structure' => $raitaru, 'bonus' => 15.0, 'category' => 'basic_capital_component'], $best);
    }

    public function testBestTimeStructureNeverRetainsAStructureWithoutTimeBonus(): void
    {
        $station = $this->createStructure('Test Station', 'station', 'highsec', []);

        $best = $this->bonusServiceForStructures([$station])->findBestStructureForCategoryTimeBonus(new User(), 'basic_capital_component', false);

        $this->assertSame(['structure' => null, 'bonus' => 0.0, 'category' => 'basic_capital_component'], $best);
    }

    public function testBestTimeStructureKeepsTheFirstOfTwoEqualStructures(): void
    {
        $firstAzbel = $this->createStructure('First Azbel', 'azbel', 'nullsec', []);
        $secondAzbel = $this->createStructure('Second Azbel', 'azbel', 'nullsec', []);

        $best = $this->bonusServiceForStructures([$firstAzbel, $secondAzbel])->findBestStructureForCategoryTimeBonus(new User(), 'equipment', false);

        $this->assertSame($firstAzbel, $best['structure']);
        $this->assertSame(20.0, $best['bonus']);
    }

    public function testBestTimeStructureRetainsTheBestManufacturingStructureAndSkipsTheRefineries(): void
    {
        $raitaruWithTimeRig = $this->createStructure('Test Raitaru', 'raitaru', 'lowsec', ['Standup M-Set Equipment Manufacturing Time Efficiency II']);
        $sotiyo = $this->createStructure('Test Sotiyo', 'sotiyo', 'lowsec', []);
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'lowsec', []);

        $best = $this->bonusServiceForStructures([$sotiyo, $tatara, $raitaruWithTimeRig])
            ->findBestStructureForCategoryTimeBonus(new User(), 'equipment', false);

        // Raitaru: rig 24 x 1.9 = 45.6 % ; 1 - 0.85 x 0.544 = 53.76 % > Sotiyo 30 %
        $this->assertSame(['structure' => $raitaruWithTimeRig, 'bonus' => 53.76, 'category' => 'equipment'], $best);
    }

    // ===========================================
    // Adjusted time per run: default arguments and rounding up
    // ===========================================

    public function testAdjustedTimeWithoutTeNorStructureBonusIsTheBaseTime(): void
    {
        $this->assertSame(3600, $this->bonusService->calculateAdjustedTimePerRun(3600));
    }

    public function testAdjustedTimeRoundsUpEvenBelowHalfASecond(): void
    {
        // 3603 x 0.80 = 2882.4 -> 2883
        $this->assertSame(2883, $this->bonusService->calculateAdjustedTimePerRun(3603, 20, 0.0));
    }

    public function testBaseMaterialBonusIsOnePercentForAnEngineeringComplexInManufacturingOnly(): void
    {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'nullsec', []);
        $azbel = $this->createStructure('Test Azbel', 'azbel', 'lowsec', []);
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'nullsec', []);
        $station = $this->createStructure('Test Station', 'station', 'highsec', []);

        $this->assertSame(1.0, $this->bonusService->getBaseMaterialBonus($raitaru, false));
        $this->assertSame(0.0, $this->bonusService->getBaseMaterialBonus($raitaru, true));
        $this->assertSame(1.0, $this->bonusService->getBaseMaterialBonus($azbel, false));
        $this->assertSame(0.0, $this->bonusService->getBaseMaterialBonus($tatara, false));
        $this->assertSame(0.0, $this->bonusService->getBaseMaterialBonus($station, false));
    }

    public function testOnlyARefineryIsSuitableForReactionsAndEveryOtherStructureForManufacturing(): void
    {
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'nullsec', []);
        $sotiyo = $this->createStructure('Test Sotiyo', 'sotiyo', 'nullsec', []);

        $this->assertTrue($this->bonusService->isSuitableForActivity($athanor, true));
        $this->assertFalse($this->bonusService->isSuitableForActivity($athanor, false));
        $this->assertTrue($this->bonusService->isSuitableForActivity($sotiyo, false));
        $this->assertFalse($this->bonusService->isSuitableForActivity($sotiyo, true));
    }

    public function testCategoryTimeBonusTakesTheBaseOfTheStructureOnlyForItsOwnActivity(): void
    {
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'nullsec', []);
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'nullsec', []);

        $this->assertSame(0.0, $this->bonusService->calculateStructureTimeBonusForCategory($tatara, 'basic_capital_component'));
        $this->assertSame(0.0, $this->bonusService->calculateStructureTimeBonusForCategory($raitaru, 'composite_reaction'));
    }

    public function testTimeBonusSkipsANonMatchingRigAndStillCountsTheNextOne(): void
    {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'highsec', [
            'Standup M-Set Basic Large Ship Manufacturing Time Efficiency II',
            'Standup M-Set Basic Capital Component Manufacturing Time Efficiency I',
        ]);

        $this->assertSame(32.0, $this->bonusService->calculateStructureTimeBonusForCategory($raitaru, 'basic_capital_component'));
    }

    public function testCategoryOfAReactionProductComesFromTheGroupOfItsFormula(): void
    {
        $this->assertSame('composite_reaction', $this->bonusServiceKnowingAReactionProduct()->getCategoryForProduct(self::REACTION_PRODUCT_TYPE_ID, true));
    }

    public function testCategoryOfAProductComesFromItsOwnGroupUnlessAReactionIsAsked(): void
    {
        $bonusService = $this->bonusServiceKnowingAReactionProduct();

        $this->assertSame('composite', $bonusService->getCategoryForProduct(self::REACTION_PRODUCT_TYPE_ID));
        $this->assertSame('composite', $bonusService->findBestStructureForProduct(new User(), self::REACTION_PRODUCT_TYPE_ID)['category']);
    }

    #[Group('issue-254')]
    public function testStructureOfUnknownTypeCurrentlyGetsNoBaseBonusAndIsNeverRetainedIssue254(): void
    {
        $fortizar = $this->createStructure('Test Fortizar', 'fortizar', 'nullsec', []);

        $this->assertSame(0.0, $this->bonusService->getBaseMaterialBonus($fortizar, false));
        $this->assertSame(0.0, $this->bonusService->getBaseTimeBonus($fortizar, false));
        $this->assertSame(
            ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0],
            $this->bonusService->calculateStructureBonusForCategory($fortizar, 'basic_capital_component'),
        );
        $this->assertSame(
            ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null],
            $this->bonusServiceForStructures([$fortizar])->findBestStructureForProduct(new User(), 16670, false),
        );
    }

    #[Group('issue-254')]
    public function testRigOfAStructureOfUnknownSecurityIsCurrentlyTreatedAsHighsecIssue254(): void
    {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'wormhole', ['Standup M-Set Basic Capital Component Manufacturing Material Efficiency II']);
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'wormhole', ['Standup L-Set Reactor Efficiency II']);

        $this->assertSame(
            ['total' => 3.38, 'base' => 1.0, 'rig' => 2.4],
            $this->bonusService->calculateStructureBonusForCategory($raitaru, 'basic_capital_component'),
        );
        $this->assertSame(
            ['total' => 2.4, 'base' => 0.0, 'rig' => 2.4],
            $this->bonusService->calculateStructureBonusForCategory($athanor, 'composite_reaction'),
        );
    }

    public static function mSetRigBonusProvider(): iterable
    {
        foreach (self::M_SET_RIG_CATEGORIES as $family => $categories) {
            foreach ($categories as $category) {
                yield "$family material efficiency I on $category: 2.0 % materials, no time" => ["Standup M-Set $family Manufacturing Material Efficiency I", $category, 2.0, 15.0];
                yield "$family material efficiency II on $category: 2.4 % materials, no time" => ["Standup M-Set $family Manufacturing Material Efficiency II", $category, 2.4, 15.0];
                yield "$family time efficiency I on $category: no materials, 20 % time" => ["Standup M-Set $family Manufacturing Time Efficiency I", $category, 0.0, 32.0];
                yield "$family time efficiency II on $category: no materials, 24 % time" => ["Standup M-Set $family Manufacturing Time Efficiency II", $category, 0.0, 35.4];
            }
        }
    }

    #[DataProvider('mSetRigBonusProvider')]
    public function testMSetRigOfARaitaruInHighsecGivesItsMaterialOrTimeBonusToEachTargetedCategory(
        string $rig,
        string $category,
        float $expectedRigMaterialBonus,
        float $expectedTimeBonusWithTheRaitaruBase,
    ): void {
        $raitaru = $this->createStructure('Test Raitaru', 'raitaru', 'highsec', [$rig]);

        $this->assertSame($expectedRigMaterialBonus, $this->bonusService->calculateStructureBonusForCategory($raitaru, $category)['rig']);
        $this->assertSame($expectedTimeBonusWithTheRaitaruBase, $this->bonusService->calculateStructureTimeBonusForCategory($raitaru, $category));
    }

    public static function lSetRigBonusProvider(): iterable
    {
        foreach (self::L_SET_RIG_CATEGORIES as $family => $categories) {
            foreach ($categories as $category) {
                yield "$family efficiency I on $category: 2.0 % materials, 20 % time" => ["Standup L-Set $family Manufacturing Efficiency I", $category, 2.0, 36.0];
                yield "$family efficiency II on $category: 2.4 % materials, 24 % time" => ["Standup L-Set $family Manufacturing Efficiency II", $category, 2.4, 39.2];
            }
        }
    }

    #[DataProvider('lSetRigBonusProvider')]
    public function testLSetRigOfAnAzbelInHighsecGivesBothItsMaterialAndTimeBonusToEachTargetedCategory(
        string $rig,
        string $category,
        float $expectedRigMaterialBonus,
        float $expectedTimeBonusWithTheAzbelBase,
    ): void {
        $azbel = $this->createStructure('Test Azbel', 'azbel', 'highsec', [$rig]);

        $this->assertSame($expectedRigMaterialBonus, $this->bonusService->calculateStructureBonusForCategory($azbel, $category)['rig']);
        $this->assertSame($expectedTimeBonusWithTheAzbelBase, $this->bonusService->calculateStructureTimeBonusForCategory($azbel, $category));
    }

    public static function xlSetRigBonusProvider(): iterable
    {
        foreach (self::XL_SET_RIG_CATEGORIES as $family => $categories) {
            foreach ($categories as $category) {
                yield "$family efficiency I on $category: 2.0 % materials, 20 % time" => ["Standup XL-Set $family Manufacturing Efficiency I", $category, 2.0, 44.0];
                yield "$family efficiency II on $category: 2.4 % materials, 24 % time" => ["Standup XL-Set $family Manufacturing Efficiency II", $category, 2.4, 46.8];
            }
        }
    }

    #[DataProvider('xlSetRigBonusProvider')]
    public function testXlSetRigOfASotiyoInHighsecGivesBothItsMaterialAndTimeBonusToEachTargetedCategory(
        string $rig,
        string $category,
        float $expectedRigMaterialBonus,
        float $expectedTimeBonusWithTheSotiyoBase,
    ): void {
        $sotiyo = $this->createStructure('Test Sotiyo', 'sotiyo', 'highsec', [$rig]);

        $this->assertSame($expectedRigMaterialBonus, $this->bonusService->calculateStructureBonusForCategory($sotiyo, $category)['rig']);
        $this->assertSame($expectedTimeBonusWithTheSotiyoBase, $this->bonusService->calculateStructureTimeBonusForCategory($sotiyo, $category));
    }

    public static function mSetReactorRigBonusProvider(): iterable
    {
        foreach (self::REACTOR_RIG_CATEGORIES as $family => $category) {
            yield "$family reactor material efficiency I: 2.0 % materials, no time" => ["Standup M-Set $family Reactor Material Efficiency I", $category, 2.0];
            yield "$family reactor material efficiency II: 2.4 % materials, no time" => ["Standup M-Set $family Reactor Material Efficiency II", $category, 2.4];
        }
    }

    #[DataProvider('mSetReactorRigBonusProvider')]
    public function testMSetReactorRigOfAnAthanorInLowsecGivesOnlyItsMaterialBonusToItsReactionCategory(
        string $rig,
        string $category,
        float $expectedRigMaterialBonus,
    ): void {
        $athanor = $this->createStructure('Test Athanor', 'athanor', 'lowsec', [$rig]);

        $this->assertSame(
            ['total' => $expectedRigMaterialBonus, 'base' => 0.0, 'rig' => $expectedRigMaterialBonus],
            $this->bonusService->calculateStructureBonusForCategory($athanor, $category),
        );
        $this->assertSame(0.0, $this->bonusService->calculateStructureTimeBonusForCategory($athanor, $category));
    }

    public static function lSetReactorRigBonusProvider(): iterable
    {
        foreach (self::REACTOR_RIG_CATEGORIES as $category) {
            yield "reactor efficiency I on $category: 2.0 % materials, 20 % time" => ['Standup L-Set Reactor Efficiency I', $category, 2.0, 40.0];
            yield "reactor efficiency II on $category: 2.4 % materials, 24 % time" => ['Standup L-Set Reactor Efficiency II', $category, 2.4, 43.0];
        }
    }

    #[DataProvider('lSetReactorRigBonusProvider')]
    public function testLSetReactorRigOfATataraInLowsecGivesBothItsMaterialAndTimeBonusToEachReactionCategory(
        string $rig,
        string $category,
        float $expectedRigMaterialBonus,
        float $expectedTimeBonusWithTheTataraBase,
    ): void {
        $tatara = $this->createStructure('Test Tatara', 'tatara', 'lowsec', [$rig]);

        $this->assertSame(
            ['total' => $expectedRigMaterialBonus, 'base' => 0.0, 'rig' => $expectedRigMaterialBonus],
            $this->bonusService->calculateStructureBonusForCategory($tatara, $category),
        );
        $this->assertSame($expectedTimeBonusWithTheTataraBase, $this->bonusService->calculateStructureTimeBonusForCategory($tatara, $category));
    }

    public static function rigAndCategoryItDoesNotTargetProvider(): iterable
    {
        $manufacturingRigs = [
            'Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency' => ['basic_capital_component'],
            'Standup M-Set Thukker Advanced Component Manufacturing Material Efficiency' => ['advanced_component'],
            'Standup L-Set Thukker Basic Capital Component Manufacturing Efficiency' => ['basic_capital_component'],
            'Standup L-Set Thukker Advanced Component Manufacturing Efficiency' => ['advanced_component'],
            'Standup XL-Set Thukker Structure and Component Manufacturing Efficiency' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component'],
        ];
        foreach (self::M_SET_RIG_CATEGORIES as $family => $categories) {
            $manufacturingRigs["Standup M-Set $family Manufacturing Material Efficiency I"] = $categories;
            $manufacturingRigs["Standup M-Set $family Manufacturing Time Efficiency I"] = $categories;
        }
        foreach (self::L_SET_RIG_CATEGORIES as $family => $categories) {
            $manufacturingRigs["Standup L-Set $family Manufacturing Efficiency I"] = $categories;
        }
        foreach (self::XL_SET_RIG_CATEGORIES as $family => $categories) {
            $manufacturingRigs["Standup XL-Set $family Manufacturing Efficiency I"] = $categories;
        }

        foreach ($manufacturingRigs as $rig => $targetedCategories) {
            foreach (array_diff(self::MANUFACTURING_CATEGORIES, $targetedCategories) as $category) {
                yield "$rig on $category" => [$rig, 'raitaru', 'highsec', $category, 15.0];
            }
            foreach (self::REACTOR_RIG_CATEGORIES as $category) {
                yield "$rig on $category" => [$rig, 'raitaru', 'highsec', $category, 0.0];
            }
        }

        foreach (self::REACTOR_RIG_CATEGORIES as $family => $targetedCategory) {
            $rig = "Standup M-Set $family Reactor Material Efficiency I";
            foreach (array_diff(self::REACTOR_RIG_CATEGORIES, [$targetedCategory]) as $category) {
                yield "$rig on $category" => [$rig, 'athanor', 'lowsec', $category, 0.0];
            }
            foreach (self::MANUFACTURING_CATEGORIES as $category) {
                yield "$rig on $category" => [$rig, 'athanor', 'lowsec', $category, 0.0];
            }
        }

        foreach (self::MANUFACTURING_CATEGORIES as $category) {
            yield "Standup L-Set Reactor Efficiency I on $category" => ['Standup L-Set Reactor Efficiency I', 'tatara', 'lowsec', $category, 0.0];
        }
    }

    #[DataProvider('rigAndCategoryItDoesNotTargetProvider')]
    public function testRigDoesNotApplyToACategoryItDoesNotTarget(
        string $rig,
        string $structureType,
        string $securityType,
        string $category,
        float $expectedBaseTimeBonusOnly,
    ): void {
        $structure = $this->createStructure('Test structure', $structureType, $securityType, [$rig]);

        $this->assertSame(0.0, $this->bonusService->calculateStructureBonusForCategory($structure, $category)['rig']);
        $this->assertSame($expectedBaseTimeBonusOnly, $this->bonusService->calculateStructureTimeBonusForCategory($structure, $category));
    }

    public static function nullsecRigProvider(): iterable
    {
        yield 'M-Set equipment ME II on a Raitaru: 2.4 x 2.1 = 5.04, 1 - 0.99 x 0.9496' => [
            'Standup M-Set Equipment Manufacturing Material Efficiency II', 'raitaru', 'equipment', ['total' => 5.99, 'base' => 1.0, 'rig' => 5.04], 15.0,
        ];
        yield 'M-Set equipment TE II on a Raitaru: 24 x 2.1 = 50.4, 1 - 0.85 x 0.496' => [
            'Standup M-Set Equipment Manufacturing Time Efficiency II', 'raitaru', 'equipment', ['total' => 1.0, 'base' => 1.0, 'rig' => 0.0], 57.84,
        ];
        yield 'L-Set ammunition I on an Azbel: 2.0 x 2.1 = 4.2 and 20 x 2.1 = 42, 1 - 0.80 x 0.58' => [
            'Standup L-Set Ammunition Manufacturing Efficiency I', 'azbel', 'ammunition', ['total' => 5.16, 'base' => 1.0, 'rig' => 4.2], 53.6,
        ];
        yield 'XL-Set ship II on a Sotiyo: 2.4 x 2.1 = 5.04 and 24 x 2.1 = 50.4, 1 - 0.70 x 0.496' => [
            'Standup XL-Set Ship Manufacturing Efficiency II', 'sotiyo', 'capital_ship', ['total' => 5.99, 'base' => 1.0, 'rig' => 5.04], 65.28,
        ];
        yield 'M-Set biochemical reactor ME I on an Athanor: 2.0 x 1.1 = 2.2' => [
            'Standup M-Set Biochemical Reactor Material Efficiency I', 'athanor', 'biochemical_reaction', ['total' => 2.2, 'base' => 0.0, 'rig' => 2.2], 0.0,
        ];
        yield 'L-Set reactor II on a Tatara: 2.4 x 1.1 = 2.64 and 24 x 1.1 = 26.4, 1 - 0.75 x 0.736' => [
            'Standup L-Set Reactor Efficiency II', 'tatara', 'hybrid_reaction', ['total' => 2.64, 'base' => 0.0, 'rig' => 2.64], 44.8,
        ];
    }

    #[DataProvider('nullsecRigProvider')]
    public function testRigInNullsecIsScaledByTheSecurityModifierOfItsActivity(
        string $rig,
        string $structureType,
        string $category,
        array $expectedMaterialBonus,
        float $expectedTimeBonus,
    ): void {
        $structure = $this->createStructure('Test structure', $structureType, 'nullsec', [$rig]);

        $this->assertSame($expectedMaterialBonus, $this->bonusService->calculateStructureBonusForCategory($structure, $category));
        $this->assertSame($expectedTimeBonus, $this->bonusService->calculateStructureTimeBonusForCategory($structure, $category));
    }

    private function bonusServiceKnowingAReactionProduct(): IndustryBonusService
    {
        $productGroup = (new InvGroup())->setGroupId(self::REACTION_PRODUCT_GROUP_ID);
        $formulaGroup = (new InvGroup())->setGroupId(self::REACTION_FORMULA_GROUP_ID);
        $types = [
            self::REACTION_PRODUCT_TYPE_ID => (new InvType())->setTypeId(self::REACTION_PRODUCT_TYPE_ID)->setGroup($productGroup),
            self::REACTION_FORMULA_TYPE_ID => (new InvType())->setTypeId(self::REACTION_FORMULA_TYPE_ID)->setGroup($formulaGroup),
        ];
        $invTypeRepository = $this->createStub(InvTypeRepository::class);
        $invTypeRepository->method('find')->willReturnCallback(static fn (int $typeId): ?InvType => $types[$typeId] ?? null);
        $activityProductRepository = $this->createStub(IndustryActivityProductRepository::class);
        $activityProductRepository->method('findBlueprintForProduct')->willReturnCallback(
            static fn (int $productTypeId, int $activityId): ?IndustryActivityProduct => $productTypeId === self::REACTION_PRODUCT_TYPE_ID && $activityId === IndustryActivityType::Reaction->value
                ? (new IndustryActivityProduct())->setTypeId(self::REACTION_FORMULA_TYPE_ID)
                : null,
        );
        $categoryRepository = $this->createStub(IndustryRigCategoryRepository::class);
        $categoryRepository->method('buildGroupCategoryMap')->willReturn([
            self::REACTION_PRODUCT_GROUP_ID => 'composite',
            self::REACTION_FORMULA_GROUP_ID => 'composite_reaction',
        ]);

        return new IndustryBonusService(
            $categoryRepository,
            $this->createStub(IndustryStructureConfigRepository::class),
            $invTypeRepository,
            $activityProductRepository,
        );
    }

    /** @param IndustryStructureConfig[] $structures */
    private function bonusServiceForStructures(array $structures): IndustryBonusService
    {
        $structureRepository = $this->createStub(IndustryStructureConfigRepository::class);
        $structureRepository->method('findByUser')->willReturn($structures);

        return new IndustryBonusService(
            $this->createStub(IndustryRigCategoryRepository::class),
            $structureRepository,
            $this->createStub(InvTypeRepository::class),
            $this->createStub(IndustryActivityProductRepository::class),
        );
    }
}
