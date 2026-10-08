<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;

/**
 * Rig bonuses read from the dogma attributes of the rig type (spec R4, D10). The SDE stores a reduction as a
 * negative percent; RigBonus carries it as a positive one. An absent attribute is null, never a default.
 */
final readonly class SdeRigBonusReader
{
    private const int ATTRIBUTE_HI_SEC_MODIFIER = 2355;
    private const int ATTRIBUTE_LOW_SEC_MODIFIER = 2356;
    private const int ATTRIBUTE_NULL_SEC_MODIFIER = 2357;
    private const int ATTRIBUTE_ENG_RIG_TIME_BONUS = 2593;
    private const int ATTRIBUTE_ENG_RIG_MAT_BONUS = 2594;
    private const int ATTRIBUTE_THUKKER_ENG_RIG_MAT_BONUS = 2653;
    private const int ATTRIBUTE_REF_RIG_TIME_BONUS = 2713;
    private const int ATTRIBUTE_REF_RIG_MAT_BONUS = 2714;

    public function __construct(private DogmaAttributeValues $dogmaAttributeValues)
    {
    }

    public function materialBonusOf(int $rigTypeId, ActivityKind $activity): ?RigBonus
    {
        return match ($activity) {
            ActivityKind::Manufacturing => $this->bonusOf($rigTypeId, self::ATTRIBUTE_ENG_RIG_MAT_BONUS),
            ActivityKind::Reaction => $this->bonusOf($rigTypeId, self::ATTRIBUTE_REF_RIG_MAT_BONUS),
            default => throw new \InvalidArgumentException(\sprintf('Rigs give no material bonus to %s.', $activity->name)),
        };
    }

    public function timeBonusOf(int $rigTypeId, ActivityKind $activity): ?RigBonus
    {
        return match ($activity) {
            ActivityKind::Manufacturing => $this->bonusOf($rigTypeId, self::ATTRIBUTE_ENG_RIG_TIME_BONUS),
            ActivityKind::Reaction => $this->bonusOf($rigTypeId, self::ATTRIBUTE_REF_RIG_TIME_BONUS),
            default => throw new \InvalidArgumentException(\sprintf('Rigs give no time bonus to %s.', $activity->name)),
        };
    }

    public function thukkerMaterialBonusOf(int $rigTypeId): ?RigBonus
    {
        return $this->bonusOf($rigTypeId, self::ATTRIBUTE_THUKKER_ENG_RIG_MAT_BONUS);
    }

    private function bonusOf(int $rigTypeId, int $bonusAttributeId): ?RigBonus
    {
        $values = $this->dogmaAttributeValues->of($rigTypeId, [
            $bonusAttributeId,
            self::ATTRIBUTE_HI_SEC_MODIFIER,
            self::ATTRIBUTE_LOW_SEC_MODIFIER,
            self::ATTRIBUTE_NULL_SEC_MODIFIER,
        ]);
        if (!\array_key_exists($bonusAttributeId, $values)) {
            return null;
        }

        return new RigBonus(
            -$values[$bonusAttributeId],
            new RigSecurityModifiers(
                $values[self::ATTRIBUTE_HI_SEC_MODIFIER] ?? null,
                $values[self::ATTRIBUTE_LOW_SEC_MODIFIER] ?? null,
                $values[self::ATTRIBUTE_NULL_SEC_MODIFIER] ?? null,
            ),
        );
    }
}
