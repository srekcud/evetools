<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Multiplier;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\SecurityClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec R4 (D6, D10): mod_rig = 1 − (rig bonus % × security modifier) / 100.
 *
 * The bonus and the security modifiers are inputs read from the rig dogma attributes of the SDE
 * (attributeEngRig*Bonus, RefRig*Bonus, attributeThukkerEngRigMatBonus, hiSecModifier / lowSecModifier / nullSecModifier),
 * passed as a positive reduction percent. No bonus table lives in the Domain.
 * Values below: SDE of the dev database on 2026-10-07.
 */
#[CoversClass(RigBonus::class)]
#[CoversClass(RigSecurityModifiers::class)]
final class RigBonusTest extends TestCase
{
    #[DataProvider('rigMultiplierProvider')]
    public function testRigMultiplierIsTheBonusScaledByTheSecurityModifierOfTheSystem(
        float $reductionPercent,
        RigSecurityModifiers $securityModifiers,
        SecurityClass $security,
        float $expectedMultiplier,
    ): void {
        $rigBonus = new RigBonus($reductionPercent, $securityModifiers);

        $this->assertEqualsWithDelta($expectedMultiplier, $rigBonus->multiplierIn($security)->value, 1e-12);
    }

    /**
     * @return iterable<string, array{float, RigSecurityModifiers, SecurityClass, float}>
     */
    public static function rigMultiplierProvider(): iterable
    {
        $manufacturingRig = self::manufacturingRigModifiers();
        $reactionRig = self::reactionRigModifiers();
        $thukkerRig = self::thukkerRigModifiers();

        // Manufacturing rig T2 2.4 % (e.g. M-Set Ammunition ME II, typeId 37159): ×1.0 / ×1.9 / ×2.1.
        yield '4.2 Hail L: manufacturing T2 rig in nullsec, 1 − 2.4 × 2.1 / 100' => [2.4, $manufacturingRig, SecurityClass::NullSec, 0.9496];
        yield 'manufacturing T2 rig in lowsec, 1 − 2.4 × 1.9 / 100' => [2.4, $manufacturingRig, SecurityClass::LowSec, 0.9544];
        yield 'manufacturing T2 rig in highsec, 1 − 2.4 × 1.0 / 100' => [2.4, $manufacturingRig, SecurityClass::HighSec, 0.976];
        yield 'manufacturing T1 rig in nullsec, 1 − 2.0 × 2.1 / 100' => [2.0, $manufacturingRig, SecurityClass::NullSec, 0.958];
        yield 'manufacturing T2 time rig in nullsec, 1 − 24 × 2.1 / 100' => [24.0, $manufacturingRig, SecurityClass::NullSec, 0.496];

        // Reaction rig T2 2.4 % (L-Set Reactor Efficiency II, typeId 46497): ×1.0 lowsec / ×1.1 nullsec (#72).
        yield '4.3 Fernite Carbide: reaction T2 rig in nullsec, 1 − 2.4 × 1.1 / 100' => [2.4, $reactionRig, SecurityClass::NullSec, 0.9736];
        yield 'reaction T2 rig in lowsec, 1 − 2.4 × 1.0 / 100' => [2.4, $reactionRig, SecurityClass::LowSec, 0.976];
        yield 'reaction T2 time rig in nullsec, 1 − 24 × 1.1 / 100' => [24.0, $reactionRig, SecurityClass::NullSec, 0.736];

        // Thukker rigs (#71): ×0.1 highsec / ×1.9 lowsec / ×0.1 nullsec.
        yield 'Thukker 2.0 % rig in highsec, 1 − 2.0 × 0.1 / 100' => [2.0, $thukkerRig, SecurityClass::HighSec, 0.998];
        yield 'Thukker 2.0 % rig in lowsec, 1 − 2.0 × 1.9 / 100' => [2.0, $thukkerRig, SecurityClass::LowSec, 0.962];
        yield 'Thukker 2.0 % rig in nullsec, 1 − 2.0 × 0.1 / 100' => [2.0, $thukkerRig, SecurityClass::NullSec, 0.998];
        yield 'Thukker basic capital component 3.7 % rig in nullsec, 1 − 3.7 × 0.1 / 100' => [3.7, $thukkerRig, SecurityClass::NullSec, 0.9963];
        yield 'Thukker basic capital component 3.7 % rig in lowsec, 1 − 3.7 × 1.9 / 100' => [3.7, $thukkerRig, SecurityClass::LowSec, 0.9297];

        yield 'rig without bonus is neutral' => [0.0, $manufacturingRig, SecurityClass::NullSec, 1.0];
    }

    public function testManufacturingSecurityModifierNeverAppliesToAReactionRig(): void
    {
        // #72: the same 2.4 % bonus in the same nullsec system gives ×0.9496 on a manufacturing rig, ×0.9736 on a reaction rig.
        $manufacturingRig = new RigBonus(2.4, self::manufacturingRigModifiers());
        $reactionRig = new RigBonus(2.4, self::reactionRigModifiers());

        $this->assertEqualsWithDelta(0.9496, $manufacturingRig->multiplierIn(SecurityClass::NullSec)->value, 1e-12);
        $this->assertEqualsWithDelta(0.9736, $reactionRig->multiplierIn(SecurityClass::NullSec)->value, 1e-12);
    }

    public function testStructureRoleBonusAndRigMultiplyIntoTheMaterialModifier(): void
    {
        // §4.2 Hail L root job: mod_matériaux = 0.99 (Raitaru) × 0.9496 (T2 rig, nullsec).
        $rigMultiplier = (new RigBonus(2.4, self::manufacturingRigModifiers()))->multiplierIn(SecurityClass::NullSec);

        $materialModifier = (new Multiplier(0.99))->times($rigMultiplier);

        $this->assertEqualsWithDelta(0.940104, $materialModifier->value, 1e-12);
    }

    public function testRefusesARigInASecurityClassWithoutModifier(): void
    {
        // The SDE has no hiSecModifier on reaction rigs: they cannot be used in highsec. Never a silent ×1.0.
        $reactionRig = new RigBonus(2.4, self::reactionRigModifiers());

        $this->expectException(\DomainException::class);

        $reactionRig->multiplierIn(SecurityClass::HighSec);
    }

    #[DataProvider('invalidReductionPercentProvider')]
    public function testRefusesANegativeOrNonFiniteBonus(float $invalidReductionPercent): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RigBonus($invalidReductionPercent, self::manufacturingRigModifiers());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidReductionPercentProvider(): iterable
    {
        yield 'negative (raw SDE sign, -2.4)' => [-2.4];
        yield 'NaN' => [\NAN];
        yield 'infinite' => [\INF];
    }

    public function testRefusesANegativeSecurityModifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RigSecurityModifiers(highSec: 1.0, lowSec: -1.9, nullSec: 2.1);
    }

    public function testRefusesANegativeHighSecModifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RigSecurityModifiers(highSec: -1.0, lowSec: 1.9, nullSec: 2.1);
    }

    #[DataProvider('securityClassProvider')]
    public function testZeroSecurityModifierIsAccepted(SecurityClass $security): void
    {
        $securityModifiers = new RigSecurityModifiers(highSec: 0.0, lowSec: 0.0, nullSec: 0.0);

        $this->assertSame(0.0, $securityModifiers->in($security));
    }

    /**
     * @return iterable<string, array{SecurityClass}>
     */
    public static function securityClassProvider(): iterable
    {
        foreach (SecurityClass::cases() as $security) {
            yield $security->name => [$security];
        }
    }

    public function testSecurityClassHasNoDedicatedWormholeCase(): void
    {
        // D6: no dedicated class for wormholes, they use NullSec (labelled "Nullsec / WH").
        $this->assertSame(['HighSec', 'LowSec', 'NullSec'], array_map(
            static fn (SecurityClass $security): string => $security->name,
            SecurityClass::cases(),
        ));
    }

    private static function manufacturingRigModifiers(): RigSecurityModifiers
    {
        return new RigSecurityModifiers(highSec: 1.0, lowSec: 1.9, nullSec: 2.1);
    }

    private static function reactionRigModifiers(): RigSecurityModifiers
    {
        return new RigSecurityModifiers(highSec: null, lowSec: 1.0, nullSec: 1.1);
    }

    private static function thukkerRigModifiers(): RigSecurityModifiers
    {
        return new RigSecurityModifiers(highSec: 0.1, lowSec: 1.9, nullSec: 0.1);
    }
}
