<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final class SkillGain
{
    private const int LAST_RAISABLE_LEVEL = 4;

    public static function timeSavedHours(float $hours, int $level, float $reductionPerLevel): float
    {
        self::assertHours($hours);
        if ($level < 0 || $level > self::LAST_RAISABLE_LEVEL) {
            throw new \InvalidArgumentException("A time skill that can be raised is at level 0 to 4, {$level} given");
        }
        if ($reductionPerLevel <= 0.0) {
            throw new \InvalidArgumentException("A time reduction per level must be positive, {$reductionPerLevel} given");
        }

        return $hours * $reductionPerLevel / (1 - $reductionPerLevel * $level);
    }

    public static function inventionSavedHours(float $hours, InventionChance $current, InventionChance $raised): float
    {
        self::assertHours($hours);

        return $hours * (1 - $current->value / $raised->value);
    }

    public static function averageSlotHours(float $categoryHours, int $slots): float
    {
        self::assertHours($categoryHours);
        if ($slots < 1) {
            throw new \InvalidArgumentException("An average slot needs at least one slot, {$slots} given");
        }

        return $categoryHours / $slots;
    }

    private static function assertHours(float $hours): void
    {
        if ($hours < 0.0) {
            throw new \InvalidArgumentException("Hours cannot be negative, {$hours} given");
        }
    }
}
