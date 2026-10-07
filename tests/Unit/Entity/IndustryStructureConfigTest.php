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
