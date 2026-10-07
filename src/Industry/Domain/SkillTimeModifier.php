<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Skill modifier of the job time, without implants (spec R7, D7).
 */
final readonly class SkillTimeModifier
{
    private const int MINIMUM_LEVEL = 0;
    private const int MAXIMUM_LEVEL = 5;

    private const float INDUSTRY_REDUCTION_PER_LEVEL = 0.04;
    private const float ADVANCED_INDUSTRY_REDUCTION_PER_LEVEL = 0.03;
    private const float SCIENCE_SKILL_REDUCTION_PER_LEVEL = 0.01;
    private const float REACTIONS_REDUCTION_PER_LEVEL = 0.04;

    /**
     * @param list<int> $requiredScienceSkillLevels levels of the science skills required by the blueprint
     */
    public static function forManufacturing(int $industryLevel, int $advancedIndustryLevel, array $requiredScienceSkillLevels): Multiplier
    {
        $modifier = self::reduction($industryLevel, self::INDUSTRY_REDUCTION_PER_LEVEL)
            * self::reduction($advancedIndustryLevel, self::ADVANCED_INDUSTRY_REDUCTION_PER_LEVEL);
        foreach ($requiredScienceSkillLevels as $scienceSkillLevel) {
            $modifier *= self::reduction($scienceSkillLevel, self::SCIENCE_SKILL_REDUCTION_PER_LEVEL);
        }

        return new Multiplier($modifier);
    }

    public static function forReaction(int $reactionsLevel): Multiplier
    {
        return new Multiplier(self::reduction($reactionsLevel, self::REACTIONS_REDUCTION_PER_LEVEL));
    }

    private static function reduction(int $skillLevel, float $reductionPerLevel): float
    {
        if ($skillLevel < self::MINIMUM_LEVEL || $skillLevel > self::MAXIMUM_LEVEL) {
            throw new \InvalidArgumentException(\sprintf('A skill level must be between %d and %d, got %d.', self::MINIMUM_LEVEL, self::MAXIMUM_LEVEL, $skillLevel));
        }

        return 1 - $reductionPerLevel * $skillLevel;
    }
}
