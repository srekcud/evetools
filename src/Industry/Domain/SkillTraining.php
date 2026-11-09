<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final class SkillTraining
{
    private const int FIRST_LEVEL = 1;
    private const int LAST_LEVEL = 5;
    private const int BASE_POINTS_PER_RANK = 250;
    private const int LEVEL_POINTS_MULTIPLIER = 32;
    private const int SECONDS_PER_MINUTE = 60;
    private const int SECONDARY_ATTRIBUTE_DIVISOR = 2;

    public static function pointsForLevel(int $rank, int $level): int
    {
        if ($rank < 1) {
            throw new \InvalidArgumentException("A skill rank is at least 1, {$rank} given");
        }
        if ($level < self::FIRST_LEVEL || $level > self::LAST_LEVEL) {
            throw new \InvalidArgumentException("A skill level is between 1 and 5, {$level} given");
        }

        return (int) ceil(self::BASE_POINTS_PER_RANK * $rank * self::LEVEL_POINTS_MULTIPLIER ** (($level - 1) / 2));
    }

    public static function secondsToNextLevel(int $rank, int $currentLevel, int $primaryAttribute, int $secondaryAttribute, int $trainedPoints): int
    {
        if ($primaryAttribute < 1 || $secondaryAttribute < 1) {
            throw new \InvalidArgumentException('A training speed needs a primary and a secondary attribute of at least 1');
        }
        if ($trainedPoints < 0) {
            throw new \InvalidArgumentException("Trained skill points cannot be negative, {$trainedPoints} given");
        }

        $nextLevelPoints = self::pointsForLevel($rank, $currentLevel + 1);
        if ($trainedPoints >= $nextLevelPoints) {
            throw new \InvalidArgumentException("{$trainedPoints} trained points already reach the next level at {$nextLevelPoints}");
        }

        $pointsAlreadyCounted = $currentLevel === 0 ? $trainedPoints : max($trainedPoints, self::pointsForLevel($rank, $currentLevel));
        $pointsToTrain = $nextLevelPoints - $pointsAlreadyCounted;
        $numerator = $pointsToTrain * self::SECONDS_PER_MINUTE * self::SECONDARY_ATTRIBUTE_DIVISOR;
        $denominator = self::SECONDARY_ATTRIBUTE_DIVISOR * $primaryAttribute + $secondaryAttribute;

        return intdiv($numerator + $denominator - 1, $denominator);
    }
}
