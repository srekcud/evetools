<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\IndustryStructureConfig;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBonusService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #72: the material bonus exposed by the structure must match the one
 * IndustryBonusService applies to project calculations (reference: SDE, benchmark F7).
 * Reaction rigs: lowsec x1.0, nullsec x1.1. Manufacturing rigs: lowsec x1.9, nullsec x2.1.
 */
#[CoversClass(IndustryStructureConfig::class)]
class IndustryStructureConfigTest extends TestCase
{
    private const string COMPOSITE_REACTOR_ME_RIG_T1 = 'Standup M-Set Composite Reactor Material Efficiency I';
    private const string COMPOSITE_REACTOR_ME_RIG_T2 = 'Standup M-Set Composite Reactor Material Efficiency II';
    private const string BASIC_LARGE_SHIP_ME_RIG_T1 = 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency I';
    private const string BASIC_LARGE_SHIP_ME_RIG_T2 = 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency II';
    private const string THUKKER_BASIC_CAPITAL_COMPONENT_ME_RIG = 'Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency';
    private const string THUKKER_ADVANCED_COMPONENT_ME_RIG = 'Standup M-Set Thukker Advanced Component Manufacturing Material Efficiency';
    private const string BASIC_LARGE_SHIP_L_RIG_T1 = 'Standup L-Set Basic Large Ship Manufacturing Efficiency I';
    private const string BASIC_LARGE_SHIP_L_RIG_T2 = 'Standup L-Set Basic Large Ship Manufacturing Efficiency II';
    private const string SHIP_XL_RIG_T1 = 'Standup XL-Set Ship Manufacturing Efficiency I';
    private const string SHIP_XL_RIG_T2 = 'Standup XL-Set Ship Manufacturing Efficiency II';
    private const string THUKKER_ADVANCED_COMPONENT_L_RIG = 'Standup L-Set Thukker Advanced Component Manufacturing Efficiency';

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

    /** @return iterable<string, array{string, string, float}> */
    public static function tataraReactionRigProvider(): iterable
    {
        yield 'T1 rig, lowsec: 2.0 x 1.0' => [self::COMPOSITE_REACTOR_ME_RIG_T1, 'lowsec', 2.0];
        yield 'T1 rig, nullsec: 2.0 x 1.1' => [self::COMPOSITE_REACTOR_ME_RIG_T1, 'nullsec', 2.2];
        yield 'T2 rig, lowsec: 2.4 x 1.0' => [self::COMPOSITE_REACTOR_ME_RIG_T2, 'lowsec', 2.4];
        yield 'T2 rig, nullsec: 2.4 x 1.1' => [self::COMPOSITE_REACTOR_ME_RIG_T2, 'nullsec', 2.64];
    }

    #[DataProvider('tataraReactionRigProvider')]
    public function testReactionMaterialBonusAppliesReactionSecurityMultiplier(
        string $reactionRig,
        string $securityType,
        float $expectedReactionMaterialBonus,
    ): void {
        $tatara = $this->createStructure('tatara', $securityType, [$reactionRig]);

        $this->assertSame($expectedReactionMaterialBonus, $tatara->getReactionMaterialBonus());
    }

    #[DataProvider('tataraReactionRigProvider')]
    public function testReactionMaterialBonusMatchesIndustryBonusService(
        string $reactionRig,
        string $securityType,
        float $expectedReactionMaterialBonus,
    ): void {
        $tatara = $this->createStructure('tatara', $securityType, [$reactionRig]);

        $appliedBonus = $this->bonusService->calculateStructureBonusForCategory($tatara, 'composite_reaction');

        // Pins the reference value on the service side, so a drift of either side is caught
        $this->assertSame($expectedReactionMaterialBonus, $appliedBonus['rig']);
        $this->assertSame($appliedBonus['rig'], $tatara->getReactionMaterialBonus());
    }

    /** @return iterable<string, array{string, string, float}> */
    public static function raitaruManufacturingRigProvider(): iterable
    {
        yield 'T1 rig, lowsec: 2.0 x 1.9' => [self::BASIC_LARGE_SHIP_ME_RIG_T1, 'lowsec', 3.8];
        yield 'T1 rig, nullsec: 2.0 x 2.1' => [self::BASIC_LARGE_SHIP_ME_RIG_T1, 'nullsec', 4.2];
        yield 'T2 rig, lowsec: 2.4 x 1.9' => [self::BASIC_LARGE_SHIP_ME_RIG_T2, 'lowsec', 4.56];
        yield 'T2 rig, nullsec: 2.4 x 2.1' => [self::BASIC_LARGE_SHIP_ME_RIG_T2, 'nullsec', 5.04];
    }

    #[DataProvider('raitaruManufacturingRigProvider')]
    public function testManufacturingMaterialBonusAppliesManufacturingSecurityMultiplier(
        string $manufacturingRig,
        string $securityType,
        float $expectedManufacturingMaterialBonus,
    ): void {
        $raitaru = $this->createStructure('raitaru', $securityType, [$manufacturingRig]);

        $appliedBonus = $this->bonusService->calculateStructureBonusForCategory($raitaru, 'basic_large_ship');

        $this->assertSame($expectedManufacturingMaterialBonus, $raitaru->getManufacturingMaterialBonus());
        $this->assertSame($appliedBonus['rig'], $raitaru->getManufacturingMaterialBonus());
    }

    // Issue #71: Athanor has no reaction time bonus, Tatara 0.75 (SDE strReactionTimeMultiplier)

    public function testAthanorHasNoReactionTimeBonus(): void
    {
        $athanor = $this->createStructure('athanor', 'nullsec', []);

        $this->assertSame(0.0, $athanor->getReactionTimeBonus());
    }

    public function testTataraReactionTimeBonusIsTwentyFivePercent(): void
    {
        $tatara = $this->createStructure('tatara', 'nullsec', []);

        $this->assertSame(25.0, $tatara->getReactionTimeBonus());
    }

    /** @return iterable<string, array{string, string, float, float}> */
    public static function refineryWithReactionMaterialRigProvider(): iterable
    {
        // [refinery, security, reaction time bonus, reaction material bonus]
        // An M-Set Material Efficiency rig adds no time: the time bonus is the refinery base alone
        yield 'Tatara, lowsec: 2.4 x 1.0' => ['tatara', 'lowsec', 25.0, 2.4];
        yield 'Tatara, nullsec: 2.4 x 1.1' => ['tatara', 'nullsec', 25.0, 2.64];
        yield 'Athanor, lowsec: 2.4 x 1.0' => ['athanor', 'lowsec', 0.0, 2.4];
        yield 'Athanor, nullsec: 2.4 x 1.1' => ['athanor', 'nullsec', 0.0, 2.64];
    }

    #[DataProvider('refineryWithReactionMaterialRigProvider')]
    public function testRefineryWithAReactionMaterialRigKeepsItsBaseTimeBonus(
        string $refineryType,
        string $securityType,
        float $expectedReactionTimeBonus,
        float $expectedReactionMaterialBonus,
    ): void {
        $refinery = $this->createStructure($refineryType, $securityType, [self::COMPOSITE_REACTOR_ME_RIG_T2]);

        $this->assertSame($expectedReactionTimeBonus, $refinery->getReactionTimeBonus());
        $this->assertSame($expectedReactionMaterialBonus, $refinery->getReactionMaterialBonus());
    }

    /** @return iterable<string, array{string, string, float}> */
    public static function refineryWithReactorEfficiencyRigProvider(): iterable
    {
        yield 'Tatara, lowsec: 2.4 x 1.0' => ['tatara', 'lowsec', 2.4];
        yield 'Tatara, nullsec: 2.4 x 1.1' => ['tatara', 'nullsec', 2.64];
        yield 'Athanor, lowsec: 2.4 x 1.0' => ['athanor', 'lowsec', 2.4];
        yield 'Athanor, nullsec: 2.4 x 1.1' => ['athanor', 'nullsec', 2.64];
    }

    /**
     * Material side only: the time side of the L-Set rig carries the scale bug tracked in #94.
     */
    #[DataProvider('refineryWithReactorEfficiencyRigProvider')]
    public function testRefineryWithAReactorEfficiencyRigGetsItsReactionMaterialBonus(
        string $refineryType,
        string $securityType,
        float $expectedReactionMaterialBonus,
    ): void {
        $refinery = $this->createStructure($refineryType, $securityType, ['Standup L-Set Reactor Efficiency II']);

        $this->assertSame($expectedReactionMaterialBonus, $refinery->getReactionMaterialBonus());
    }

    // Issue #71: Thukker rigs, 3.7 % on basic capital components, 2.0 % otherwise, x0.1 highsec / x1.9 lowsec / x0.1 nullsec

    /** @return iterable<string, array{string, string, float}> */
    public static function thukkerManufacturingRigProvider(): iterable
    {
        yield 'M-Set basic capital, highsec: 3.7 x 0.1' => [self::THUKKER_BASIC_CAPITAL_COMPONENT_ME_RIG, 'highsec', 0.37];
        yield 'M-Set basic capital, lowsec: 3.7 x 1.9' => [self::THUKKER_BASIC_CAPITAL_COMPONENT_ME_RIG, 'lowsec', 7.03];
        yield 'M-Set basic capital, nullsec: 3.7 x 0.1' => [self::THUKKER_BASIC_CAPITAL_COMPONENT_ME_RIG, 'nullsec', 0.37];
        yield 'M-Set advanced component, highsec: 2.0 x 0.1' => [self::THUKKER_ADVANCED_COMPONENT_ME_RIG, 'highsec', 0.2];
        yield 'M-Set advanced component, lowsec: 2.0 x 1.9' => [self::THUKKER_ADVANCED_COMPONENT_ME_RIG, 'lowsec', 3.8];
        yield 'M-Set advanced component, nullsec: 2.0 x 0.1' => [self::THUKKER_ADVANCED_COMPONENT_ME_RIG, 'nullsec', 0.2];
    }

    #[DataProvider('thukkerManufacturingRigProvider')]
    public function testThukkerRigMaterialBonusUsesTheThukkerSecurityModifiers(
        string $thukkerRig,
        string $securityType,
        float $expectedManufacturingMaterialBonus,
    ): void {
        $raitaru = $this->createStructure('raitaru', $securityType, [$thukkerRig]);

        $this->assertSame($expectedManufacturingMaterialBonus, $raitaru->getManufacturingMaterialBonus());
    }

    // Issue #94: the time bonus of a standard L-Set / XL-Set rig is 20 % (T1) or 24 % (T2), SDE attributeEngRigTimeBonus (2593),
    // ten times its material bonus, then scaled by the manufacturing security multiplier (x1.0 / x1.9 / x2.1).
    // Stacking with the structure base time bonus (Raitaru 15, Azbel 20, Sotiyo 30) is multiplicative.

    /** @return iterable<string, array{string, string, string, float}> */
    public static function standardLargeRigManufacturingTimeProvider(): iterable
    {
        // The issue's scenario: the entity does not check rig size against the hull
        yield 'Raitaru, L T1, highsec: 1 - 0.85 x (1 - 0.20)' => ['raitaru', self::BASIC_LARGE_SHIP_L_RIG_T1, 'highsec', 32.0];
        yield 'Raitaru, L T2, highsec: 1 - 0.85 x (1 - 0.24)' => ['raitaru', self::BASIC_LARGE_SHIP_L_RIG_T2, 'highsec', 35.4];
        yield 'Raitaru, L T1, lowsec: 1 - 0.85 x (1 - 0.38)' => ['raitaru', self::BASIC_LARGE_SHIP_L_RIG_T1, 'lowsec', 47.3];
        yield 'Raitaru, L T2, lowsec: 1 - 0.85 x (1 - 0.456)' => ['raitaru', self::BASIC_LARGE_SHIP_L_RIG_T2, 'lowsec', 53.76];
        yield 'Raitaru, L T1, nullsec: 1 - 0.85 x (1 - 0.42)' => ['raitaru', self::BASIC_LARGE_SHIP_L_RIG_T1, 'nullsec', 50.7];
        yield 'Raitaru, L T2, nullsec: 1 - 0.85 x (1 - 0.504)' => ['raitaru', self::BASIC_LARGE_SHIP_L_RIG_T2, 'nullsec', 57.84];

        yield 'Azbel, L T1, highsec: 1 - 0.80 x (1 - 0.20)' => ['azbel', self::BASIC_LARGE_SHIP_L_RIG_T1, 'highsec', 36.0];
        yield 'Azbel, L T2, highsec: 1 - 0.80 x (1 - 0.24)' => ['azbel', self::BASIC_LARGE_SHIP_L_RIG_T2, 'highsec', 39.2];
        yield 'Azbel, L T1, lowsec: 1 - 0.80 x (1 - 0.38)' => ['azbel', self::BASIC_LARGE_SHIP_L_RIG_T1, 'lowsec', 50.4];
        yield 'Azbel, L T2, lowsec: 1 - 0.80 x (1 - 0.456)' => ['azbel', self::BASIC_LARGE_SHIP_L_RIG_T2, 'lowsec', 56.48];
        yield 'Azbel, L T1, nullsec: 1 - 0.80 x (1 - 0.42)' => ['azbel', self::BASIC_LARGE_SHIP_L_RIG_T1, 'nullsec', 53.6];
        yield 'Azbel, L T2, nullsec: 1 - 0.80 x (1 - 0.504)' => ['azbel', self::BASIC_LARGE_SHIP_L_RIG_T2, 'nullsec', 60.32];

        yield 'Sotiyo, XL T1, highsec: 1 - 0.70 x (1 - 0.20)' => ['sotiyo', self::SHIP_XL_RIG_T1, 'highsec', 44.0];
        yield 'Sotiyo, XL T2, highsec: 1 - 0.70 x (1 - 0.24)' => ['sotiyo', self::SHIP_XL_RIG_T2, 'highsec', 46.8];
        yield 'Sotiyo, XL T1, lowsec: 1 - 0.70 x (1 - 0.38)' => ['sotiyo', self::SHIP_XL_RIG_T1, 'lowsec', 56.6];
        yield 'Sotiyo, XL T2, lowsec: 1 - 0.70 x (1 - 0.456)' => ['sotiyo', self::SHIP_XL_RIG_T2, 'lowsec', 61.92];
        yield 'Sotiyo, XL T1, nullsec: 1 - 0.70 x (1 - 0.42)' => ['sotiyo', self::SHIP_XL_RIG_T1, 'nullsec', 59.4];
        yield 'Sotiyo, XL T2, nullsec: 1 - 0.70 x (1 - 0.504)' => ['sotiyo', self::SHIP_XL_RIG_T2, 'nullsec', 65.28];
    }

    #[DataProvider('standardLargeRigManufacturingTimeProvider')]
    public function testStandardLargeRigManufacturingTimeBonusIsTwentyOrTwentyFourPercentScaledBySecurity(
        string $structureType,
        string $timeRig,
        string $securityType,
        float $expectedManufacturingTimeBonus,
    ): void {
        $structure = $this->createStructure($structureType, $securityType, [$timeRig]);

        $this->assertSame($expectedManufacturingTimeBonus, $structure->getManufacturingTimeBonus());
    }

    #[DataProvider('standardLargeRigManufacturingTimeProvider')]
    public function testStandardLargeRigManufacturingTimeBonusMatchesIndustryBonusService(
        string $structureType,
        string $timeRig,
        string $securityType,
        float $expectedManufacturingTimeBonus,
    ): void {
        $structure = $this->createStructure($structureType, $securityType, [$timeRig]);

        $appliedTimeBonus = $this->bonusService->calculateStructureTimeBonusForCategory($structure, 'basic_large_ship');

        // Pins the reference value on the service side, so a drift of either side is caught
        $this->assertSame($expectedManufacturingTimeBonus, $appliedTimeBonus);
        $this->assertSame($appliedTimeBonus, $structure->getManufacturingTimeBonus());
    }

    // Guard rails for #94: Thukker L-Set rigs (#71, 20 % x0.1 / x1.9 / x0.1) and M-Set material rigs (no time bonus) stay as they are

    /** @return iterable<string, array{string, float}> */
    public static function thukkerLargeRigManufacturingTimeProvider(): iterable
    {
        yield 'Azbel, Thukker L, highsec: 1 - 0.80 x (1 - 0.02)' => ['highsec', 21.6];
        yield 'Azbel, Thukker L, lowsec: 1 - 0.80 x (1 - 0.38)' => ['lowsec', 50.4];
        yield 'Azbel, Thukker L, nullsec: 1 - 0.80 x (1 - 0.02)' => ['nullsec', 21.6];
    }

    #[DataProvider('thukkerLargeRigManufacturingTimeProvider')]
    public function testThukkerLargeRigManufacturingTimeBonusMatchesIndustryBonusService(
        string $securityType,
        float $expectedManufacturingTimeBonus,
    ): void {
        $azbel = $this->createStructure('azbel', $securityType, [self::THUKKER_ADVANCED_COMPONENT_L_RIG]);

        $appliedTimeBonus = $this->bonusService->calculateStructureTimeBonusForCategory($azbel, 'advanced_component');

        $this->assertSame($expectedManufacturingTimeBonus, $appliedTimeBonus);
        $this->assertSame($expectedManufacturingTimeBonus, $azbel->getManufacturingTimeBonus());
    }

    /** @return iterable<string, array{string, string}> */
    public static function mediumMaterialRigProvider(): iterable
    {
        foreach (['highsec', 'lowsec', 'nullsec'] as $securityType) {
            yield "M T1, {$securityType}" => [self::BASIC_LARGE_SHIP_ME_RIG_T1, $securityType];
            yield "M T2, {$securityType}" => [self::BASIC_LARGE_SHIP_ME_RIG_T2, $securityType];
        }
    }

    #[DataProvider('mediumMaterialRigProvider')]
    public function testMediumMaterialRigAddsNoManufacturingTimeBonus(string $materialRig, string $securityType): void
    {
        $raitaru = $this->createStructure('raitaru', $securityType, [$materialRig]);

        $appliedTimeBonus = $this->bonusService->calculateStructureTimeBonusForCategory($raitaru, 'basic_large_ship');

        // Raitaru base time bonus alone
        $this->assertSame(15.0, $appliedTimeBonus);
        $this->assertSame(15.0, $raitaru->getManufacturingTimeBonus());
    }

    /** @param string[] $rigs */
    private function createStructure(string $structureType, string $securityType, array $rigs): IndustryStructureConfig
    {
        $structure = new IndustryStructureConfig();
        $structure->setName('Test '.$structureType);
        $structure->setStructureType($structureType);
        $structure->setSecurityType($securityType);
        $structure->setRigs($rigs);

        return $structure;
    }
}
