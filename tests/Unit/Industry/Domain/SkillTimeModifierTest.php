<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Domain;

use App\Industry\Domain\SkillTimeModifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec R7: skill modifier of the job time (no implants, D7).
 *
 * manufacturing: (1 − 4 % × Industry) × (1 − 3 % × Advanced Industry) × Π (1 − 1 % × required science skill)
 * reaction:      (1 − 4 % × Reactions)
 */
#[CoversClass(SkillTimeModifier::class)]
final class SkillTimeModifierTest extends TestCase
{
    /**
     * @param list<int> $requiredScienceSkillLevels
     */
    #[DataProvider('manufacturingSkillsProvider')]
    public function testManufacturingSkillModifierMultipliesEachSkillReduction(
        int $industryLevel,
        int $advancedIndustryLevel,
        array $requiredScienceSkillLevels,
        float $expectedModifier,
    ): void {
        $modifier = SkillTimeModifier::forManufacturing($industryLevel, $advancedIndustryLevel, $requiredScienceSkillLevels);

        $this->assertEqualsWithDelta($expectedModifier, $modifier->value, 1e-12);
    }

    /**
     * @return iterable<string, array{int, int, list<int>, float}>
     */
    public static function manufacturingSkillsProvider(): iterable
    {
        yield 'no skill trained' => [0, 0, [], 1.0];
        yield 'Industry V only: 1 − 0.20' => [5, 0, [], 0.8];
        yield 'Industry V, Advanced Industry V, T1 (no science skill): 0.8 × 0.85' => [5, 5, [], 0.68];
        yield 'all V with two science skills: 0.8 × 0.85 × 0.95 × 0.95' => [5, 5, [5, 5], 0.6137];
        yield 'Industry IV, Advanced Industry III, science IV and II: 0.84 × 0.91 × 0.96 × 0.98' => [4, 3, [4, 2], 0.71914752];
    }

    #[DataProvider('reactionSkillProvider')]
    public function testReactionSkillModifierDependsOnReactionsOnly(int $reactionsLevel, float $expectedModifier): void
    {
        $this->assertEqualsWithDelta($expectedModifier, SkillTimeModifier::forReaction($reactionsLevel)->value, 1e-12);
    }

    /**
     * @return iterable<string, array{int, float}>
     */
    public static function reactionSkillProvider(): iterable
    {
        yield 'Reactions 0' => [0, 1.0];
        yield 'Reactions III: 1 − 0.12' => [3, 0.88];
        yield 'Reactions V: 1 − 0.20' => [5, 0.8];
    }

    /**
     * @param list<int> $requiredScienceSkillLevels
     */
    #[DataProvider('invalidManufacturingSkillLevelProvider')]
    public function testRefusesAManufacturingSkillLevelOutsideZeroToFive(
        int $industryLevel,
        int $advancedIndustryLevel,
        array $requiredScienceSkillLevels,
    ): void {
        $this->expectException(\InvalidArgumentException::class);

        SkillTimeModifier::forManufacturing($industryLevel, $advancedIndustryLevel, $requiredScienceSkillLevels);
    }

    /**
     * @return iterable<string, array{int, int, list<int>}>
     */
    public static function invalidManufacturingSkillLevelProvider(): iterable
    {
        yield 'Industry 6' => [6, 5, []];
        yield 'Advanced Industry -1' => [5, -1, []];
        yield 'science skill 6' => [5, 5, [5, 6]];
    }

    public function testRefusesAReactionsLevelOutsideZeroToFive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        SkillTimeModifier::forReaction(6);
    }
}
