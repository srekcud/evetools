<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Service\Industry\IndustryBonusService;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
