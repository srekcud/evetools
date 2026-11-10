<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class ResearchJobDuration
{
    public const int METALLURGY_SKILL_TYPE_ID = 3409;
    public const int RESEARCH_SKILL_TYPE_ID = 3403;
    public const int ADVANCED_INDUSTRY_SKILL_TYPE_ID = 3388;

    private const int MINIMUM_SKILL_LEVEL = 0;
    private const int MAXIMUM_SKILL_LEVEL = 5;

    private const float RESEARCH_SKILL_REDUCTION_PER_LEVEL = 0.05;
    private const float ADVANCED_INDUSTRY_REDUCTION_PER_LEVEL = 0.03;

    /**
     * @param list<MissingData> $missingData
     */
    private function __construct(private ?JobDuration $knownDuration, public array $missingData)
    {
    }

    /**
     * @param list<MissingData> $missingData
     */
    public static function unknown(array $missingData): self
    {
        return new self(null, $missingData);
    }

    public static function of(
        ActivityKind $activity,
        int $baseTimeSeconds,
        int $targetLevel,
        ?int $researchSkillLevel,
        ?int $advancedIndustryLevel,
        Multiplier $structureTimeModifier,
    ): self {
        $researchSkillTypeId = match ($activity) {
            ActivityKind::ResearchMaterial => self::METALLURGY_SKILL_TYPE_ID,
            ActivityKind::ResearchTime => self::RESEARCH_SKILL_TYPE_ID,
            default => throw new \InvalidArgumentException(\sprintf('%s is not a research activity.', $activity->name)),
        };
        if ($baseTimeSeconds < 0) {
            throw new \InvalidArgumentException(\sprintf('A base time cannot be negative, got %d seconds.', $baseTimeSeconds));
        }
        $levelMultiplier = ResearchLevels::costMultiplier($targetLevel);
        foreach ([$researchSkillLevel, $advancedIndustryLevel] as $skillLevel) {
            if (null !== $skillLevel && ($skillLevel < self::MINIMUM_SKILL_LEVEL || $skillLevel > self::MAXIMUM_SKILL_LEVEL)) {
                throw new \InvalidArgumentException(\sprintf('A skill level must be between %d and %d, got %d.', self::MINIMUM_SKILL_LEVEL, self::MAXIMUM_SKILL_LEVEL, $skillLevel));
            }
        }

        $missingData = [];
        if (null === $researchSkillLevel) {
            $missingData[] = MissingData::researchSkill($researchSkillTypeId);
        }
        if (null === $advancedIndustryLevel) {
            $missingData[] = MissingData::researchSkill(self::ADVANCED_INDUSTRY_SKILL_TYPE_ID);
        }
        if ([] !== $missingData) {
            return new self(null, $missingData);
        }

        $seconds = $baseTimeSeconds
            * $levelMultiplier
            * (1 - self::RESEARCH_SKILL_REDUCTION_PER_LEVEL * $researchSkillLevel)
            * (1 - self::ADVANCED_INDUSTRY_REDUCTION_PER_LEVEL * $advancedIndustryLevel)
            * $structureTimeModifier->value;

        return new self(new JobDuration((int) round($seconds)), []);
    }

    public function isKnown(): bool
    {
        return null !== $this->knownDuration;
    }

    public function jobDuration(): JobDuration
    {
        if (null === $this->knownDuration) {
            throw new \LogicException('An unknown research duration has no value: check isKnown() first.');
        }

        return $this->knownDuration;
    }
}
