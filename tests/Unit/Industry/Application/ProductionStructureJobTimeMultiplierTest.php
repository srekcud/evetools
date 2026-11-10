<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\StructureType;
use App\Industry\Application\TimeRig;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\SecurityClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductionStructure::class)]
#[CoversClass(TimeRig::class)]
#[Group('issue-338')]
final class ProductionStructureJobTimeMultiplierTest extends TestCase
{
    private const float FLOAT_TOLERANCE = 1e-12;

    private const float RAITARU_TIME_ROLE_BONUS = 0.85;
    private const float TATARA_REACTION_TIME_ROLE_BONUS = 0.75;

    private const float TIME_RIG_I_REDUCTION_PERCENT = 20.0;
    private const float ENGINEERING_NULLSEC_MODIFIER = 2.1;
    private const float REFINERY_NULLSEC_MODIFIER = 1.1;
    private const float ENGINEERING_LOWSEC_MODIFIER = 1.9;

    private const float ENGINEERING_TIME_RIG_NULLSEC_MULTIPLIER = 0.58;
    private const float ENGINEERING_TIME_RIG_LOWSEC_MULTIPLIER = 0.62;
    private const float REFINERY_TIME_RIG_NULLSEC_MULTIPLIER = 0.78;

    public function testManufacturingTimeIsTheRoleBonusTimesTheTimeRigsTargetingTheProductCategory(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, [self::engineeringTimeRig('equipment')]);

        $this->assertEqualsWithDelta(
            self::RAITARU_TIME_ROLE_BONUS * self::ENGINEERING_TIME_RIG_NULLSEC_MULTIPLIER,
            $raitaru->jobTimeMultiplierFor(ActivityKind::Manufacturing, 'equipment')->value,
            self::FLOAT_TOLERANCE,
        );
    }

    public function testManufacturingTimeIgnoresTheTimeRigsOfOtherCategories(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, [self::engineeringTimeRig('equipment')]);

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->jobTimeMultiplierFor(ActivityKind::Manufacturing, 'ammunition')->value, self::FLOAT_TOLERANCE);
    }

    public function testManufacturingTimeOfAProductWithoutCategoryIsTheRoleBonusOnly(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, [self::engineeringTimeRig('equipment')]);

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $raitaru->jobTimeMultiplierFor(ActivityKind::Manufacturing, null)->value, self::FLOAT_TOLERANCE);
    }

    public function testTwoTimeRigsOnTheSameCategoryMultiplyTheirReductions(): void
    {
        $raitaru = self::raitaru(SecurityClass::NullSec, [self::engineeringTimeRig('equipment'), self::engineeringTimeRig('equipment', 'ammunition')]);

        $this->assertEqualsWithDelta(
            self::RAITARU_TIME_ROLE_BONUS * self::ENGINEERING_TIME_RIG_NULLSEC_MULTIPLIER * self::ENGINEERING_TIME_RIG_NULLSEC_MULTIPLIER,
            $raitaru->jobTimeMultiplierFor(ActivityKind::Manufacturing, 'equipment')->value,
            self::FLOAT_TOLERANCE,
        );
    }

    public function testTimeRigIsScaledByTheSecurityClassOfTheStructure(): void
    {
        $raitaru = self::raitaru(SecurityClass::LowSec, [self::engineeringTimeRig('equipment')]);

        $this->assertEqualsWithDelta(
            self::RAITARU_TIME_ROLE_BONUS * self::ENGINEERING_TIME_RIG_LOWSEC_MULTIPLIER,
            $raitaru->jobTimeMultiplierFor(ActivityKind::Manufacturing, 'equipment')->value,
            self::FLOAT_TOLERANCE,
        );
    }

    public function testReactionTimeIsTheReactionRoleBonusTimesTheTimeRigsTargetingTheFormulaCategory(): void
    {
        $tatara = self::tatara([self::refineryTimeRig('composite_reaction')]);

        $this->assertEqualsWithDelta(
            self::TATARA_REACTION_TIME_ROLE_BONUS * self::REFINERY_TIME_RIG_NULLSEC_MULTIPLIER,
            $tatara->jobTimeMultiplierFor(ActivityKind::Reaction, 'composite_reaction')->value,
            self::FLOAT_TOLERANCE,
        );
        $this->assertEqualsWithDelta(self::TATARA_REACTION_TIME_ROLE_BONUS, $tatara->jobTimeMultiplierFor(ActivityKind::Reaction, 'hybrid_reaction')->value, self::FLOAT_TOLERANCE);
    }

    public function testManufacturingLeavesTheReactionRoleBonusAndReactionLeavesTheManufacturingOne(): void
    {
        $structure = new ProductionStructure(
            'both-role-bonuses',
            StructureType::Refinery,
            SecurityClass::NullSec,
            null,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            Multiplier::one(),
            [],
            Multiplier::one(),
            0.0,
            new Multiplier(self::RAITARU_TIME_ROLE_BONUS),
            [],
            new Multiplier(self::TATARA_REACTION_TIME_ROLE_BONUS),
        );

        $this->assertEqualsWithDelta(self::RAITARU_TIME_ROLE_BONUS, $structure->jobTimeMultiplierFor(ActivityKind::Manufacturing, null)->value, self::FLOAT_TOLERANCE);
        $this->assertEqualsWithDelta(self::TATARA_REACTION_TIME_ROLE_BONUS, $structure->jobTimeMultiplierFor(ActivityKind::Reaction, null)->value, self::FLOAT_TOLERANCE);
    }

    public function testStructureWithoutRoleBonusNorTimeRigRunsAtTheBaseTime(): void
    {
        $structure = new ProductionStructure(
            'plain',
            StructureType::EngineeringComplex,
            SecurityClass::NullSec,
            null,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            Multiplier::one(),
            [],
            Multiplier::one(),
            0.0,
        );

        $this->assertSame(1.0, $structure->jobTimeMultiplierFor(ActivityKind::Manufacturing, 'equipment')->value);
        $this->assertSame(1.0, $structure->jobTimeMultiplierFor(ActivityKind::Reaction, 'composite_reaction')->value);
    }

    public function testJobTimeIsOnlyModelledForManufacturingAndReaction(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::raitaru(SecurityClass::NullSec, [])->jobTimeMultiplierFor(ActivityKind::Copying, null);
    }

    public function testTimeRigTargetsTheCategoriesItWasGiven(): void
    {
        $rig = self::engineeringTimeRig('equipment', 'drone');

        $this->assertTrue($rig->targets('equipment'));
        $this->assertTrue($rig->targets('drone'));
        $this->assertFalse($rig->targets('ammunition'));
    }

    /**
     * @param list<TimeRig> $timeRigs
     */
    private static function raitaru(SecurityClass $security, array $timeRigs): ProductionStructure
    {
        return new ProductionStructure(
            'raitaru',
            StructureType::EngineeringComplex,
            $security,
            null,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new Multiplier(0.99),
            [],
            new Multiplier(0.97),
            0.0,
            new Multiplier(self::RAITARU_TIME_ROLE_BONUS),
            [],
            Multiplier::one(),
            $timeRigs,
        );
    }

    /**
     * @param list<TimeRig> $timeRigs
     */
    private static function tatara(array $timeRigs): ProductionStructure
    {
        return new ProductionStructure(
            'tatara',
            StructureType::Refinery,
            SecurityClass::NullSec,
            null,
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            Multiplier::one(),
            [],
            Multiplier::one(),
            0.0,
            Multiplier::one(),
            [],
            new Multiplier(self::TATARA_REACTION_TIME_ROLE_BONUS),
            $timeRigs,
        );
    }

    private static function engineeringTimeRig(string ...$targetedCategories): TimeRig
    {
        return new TimeRig(
            new RigBonus(self::TIME_RIG_I_REDUCTION_PERCENT, new RigSecurityModifiers(1.0, self::ENGINEERING_LOWSEC_MODIFIER, self::ENGINEERING_NULLSEC_MODIFIER)),
            array_values($targetedCategories),
        );
    }

    private static function refineryTimeRig(string ...$targetedCategories): TimeRig
    {
        return new TimeRig(
            new RigBonus(self::TIME_RIG_I_REDUCTION_PERCENT, new RigSecurityModifiers(null, 1.0, self::REFINERY_NULLSEC_MODIFIER)),
            array_values($targetedCategories),
        );
    }
}
