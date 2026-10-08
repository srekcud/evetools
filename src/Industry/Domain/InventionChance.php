<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Probability of success of an invention attempt (spec R8).
 */
final readonly class InventionChance
{
    private const int MINIMUM_SKILL_LEVEL = 0;
    private const int MAXIMUM_SKILL_LEVEL = 5;

    /** Game rule: each level of a required science skill adds 1/30 of the base probability, each encryption level 1/40. */
    private const float SCIENCE_SKILL_LEVELS_PER_BASE_PROBABILITY = 30.0;
    private const float ENCRYPTION_SKILL_LEVELS_PER_BASE_PROBABILITY = 40.0;

    private const float CERTAINTY = 1.0;

    private function __construct(public float $value)
    {
    }

    /**
     * @param float $baseProbability SDE probability of the invention activity; a missing one is no chance at all, never 0
     */
    public static function of(
        float $baseProbability,
        int $firstScienceSkillLevel,
        int $secondScienceSkillLevel,
        int $encryptionSkillLevel,
        ?Decryptor $decryptor,
    ): self {
        // Written as a negation so that NaN is refused too.
        if (!($baseProbability > 0.0 && $baseProbability <= self::CERTAINTY)) {
            throw new \InvalidArgumentException(\sprintf('A base invention probability must be in ]0, 1], got %F.', $baseProbability));
        }
        foreach ([$firstScienceSkillLevel, $secondScienceSkillLevel, $encryptionSkillLevel] as $skillLevel) {
            self::assertSkillLevel($skillLevel);
        }

        $skillBonus = 1
            + ($firstScienceSkillLevel + $secondScienceSkillLevel) / self::SCIENCE_SKILL_LEVELS_PER_BASE_PROBABILITY
            + $encryptionSkillLevel / self::ENCRYPTION_SKILL_LEVELS_PER_BASE_PROBABILITY;
        $decryptorMultiplier = null === $decryptor ? Multiplier::one() : $decryptor->probabilityMultiplier;

        return new self(min(self::CERTAINTY, $baseProbability * $skillBonus * $decryptorMultiplier->value));
    }

    private static function assertSkillLevel(int $skillLevel): void
    {
        if ($skillLevel < self::MINIMUM_SKILL_LEVEL || $skillLevel > self::MAXIMUM_SKILL_LEVEL) {
            throw new \InvalidArgumentException(\sprintf('A skill level must be between %d and %d, got %d.', self::MINIMUM_SKILL_LEVEL, self::MAXIMUM_SKILL_LEVEL, $skillLevel));
        }
    }
}
