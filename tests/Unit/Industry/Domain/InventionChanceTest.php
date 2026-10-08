<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\Decryptor;
use App\Industry\Domain\InventionChance;
use App\Industry\Domain\Multiplier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec R8 (benchmark F8): probability of success of an invention attempt.
 *
 * P = min(1, base × (1 + (science1 + science2) / 30 + encryption / 40) × decryptor probability multiplier)
 *
 * Base probabilities from the SDE (activity 8): Hobgoblin I Blueprint 0.34, Rifter Blueprint 0.30.
 * Decryptor multipliers from the SDE dogma attribute inventionPropabilityMultiplier (table fixed by #70).
 */
#[CoversClass(InventionChance::class)]
#[CoversClass(Decryptor::class)]
final class InventionChanceTest extends TestCase
{
    private const float PROBABILITY_DELTA = 1e-12;

    #[DataProvider('inventionChanceProvider')]
    public function testInventionChanceAddsTheSkillsThenAppliesTheDecryptor(
        float $baseProbability,
        int $firstScienceSkillLevel,
        int $secondScienceSkillLevel,
        int $encryptionSkillLevel,
        ?Decryptor $decryptor,
        float $expectedProbability,
    ): void {
        $chance = InventionChance::of($baseProbability, $firstScienceSkillLevel, $secondScienceSkillLevel, $encryptionSkillLevel, $decryptor);

        $this->assertEqualsWithDelta($expectedProbability, $chance->value, self::PROBABILITY_DELTA);
    }

    /**
     * @return iterable<string, array{float, int, int, int, ?Decryptor, float}>
     */
    public static function inventionChanceProvider(): iterable
    {
        // Today InventionService ignores the skills: P = base × multiplier (benchmark F8, #18).
        yield 'no skill, no decryptor: the base probability' => [0.34, 0, 0, 0, null, 0.34];
        yield 'Hobgoblin II, science IV/IV, encryption IV: 0.34 × (1 + 8/30 + 4/40)' => [0.34, 4, 4, 4, null, 0.464666666666667];
        yield 'Hobgoblin II, all skills V: 0.34 × (1 + 10/30 + 5/40)' => [0.34, 5, 5, 5, null, 0.495833333333333];
        yield 'Hobgoblin II, skills IV, Attainment ×1.8' => [0.34, 4, 4, 4, self::attainment(), 0.8364];
        yield 'Wolf, science III/II, encryption I, Augmentation ×0.6: 0.30 × (1 + 5/30 + 1/40) × 0.6' => [0.30, 3, 2, 1, self::augmentation(), 0.2145];
    }

    public function testInventionChanceIsCappedAtOne(): void
    {
        // 0.40 × (1 + 10/30 + 5/40) × 1.9 = 1.108… : a probability never exceeds 1.
        $chance = InventionChance::of(0.40, 5, 5, 5, self::optimizedAttainment());

        $this->assertSame(1.0, $chance->value);
    }

    /**
     * A missing probability is not a 0 probability: the caller passes no chance at all (see InventionExpectationTest).
     */
    #[DataProvider('invalidBaseProbabilityProvider')]
    public function testRefusesABaseProbabilityOutsideZeroExcludedToOne(float $baseProbability): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InventionChance::of($baseProbability, 4, 4, 4, null);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidBaseProbabilityProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.34];
        yield 'above one' => [1.01];
        yield 'NaN' => [\NAN];
    }

    #[DataProvider('invalidSkillLevelProvider')]
    public function testRefusesASkillLevelOutsideZeroToFive(int $firstScienceSkillLevel, int $secondScienceSkillLevel, int $encryptionSkillLevel): void
    {
        $this->expectException(\InvalidArgumentException::class);

        InventionChance::of(0.34, $firstScienceSkillLevel, $secondScienceSkillLevel, $encryptionSkillLevel, null);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalidSkillLevelProvider(): iterable
    {
        yield 'first science skill 6' => [6, 4, 4];
        yield 'second science skill -1' => [4, -1, 4];
        yield 'encryption skill 6' => [4, 4, 6];
    }

    private static function attainment(): Decryptor
    {
        return new Decryptor(34202, new Multiplier(1.8), 4, -1, 4);
    }

    private static function augmentation(): Decryptor
    {
        return new Decryptor(34203, new Multiplier(0.6), 9, -2, 2);
    }

    private static function optimizedAttainment(): Decryptor
    {
        return new Decryptor(34207, new Multiplier(1.9), 2, 1, -2);
    }
}
