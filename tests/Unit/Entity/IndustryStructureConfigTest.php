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
