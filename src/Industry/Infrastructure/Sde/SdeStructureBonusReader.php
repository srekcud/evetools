<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Multiplier;

/**
 * Structure role bonuses read from the dogma attributes of the structure type, already stored as multipliers
 * (spec R4, D10). An absent attribute means "no role bonus": null, never a default.
 */
final readonly class SdeStructureBonusReader
{
    private const int ATTRIBUTE_STR_ENG_MAT_BONUS = 2600;
    private const int ATTRIBUTE_STR_ENG_COST_BONUS = 2601;
    private const int ATTRIBUTE_STR_ENG_TIME_BONUS = 2602;
    private const int ATTRIBUTE_STR_REACTION_TIME_MULTIPLIER = 2721;

    public function __construct(private DogmaAttributeValues $dogmaAttributeValues)
    {
    }

    public function materialMultiplierOf(int $structureTypeId): ?Multiplier
    {
        return $this->multiplierOf($structureTypeId, self::ATTRIBUTE_STR_ENG_MAT_BONUS);
    }

    public function costMultiplierOf(int $structureTypeId): ?Multiplier
    {
        return $this->multiplierOf($structureTypeId, self::ATTRIBUTE_STR_ENG_COST_BONUS);
    }

    public function timeMultiplierOf(int $structureTypeId, ActivityKind $activity): ?Multiplier
    {
        return match ($activity) {
            ActivityKind::Manufacturing => $this->multiplierOf($structureTypeId, self::ATTRIBUTE_STR_ENG_TIME_BONUS),
            ActivityKind::Reaction => $this->multiplierOf($structureTypeId, self::ATTRIBUTE_STR_REACTION_TIME_MULTIPLIER),
            default => throw new \InvalidArgumentException(\sprintf('Structures give no time role bonus to %s.', $activity->name)),
        };
    }

    private function multiplierOf(int $structureTypeId, int $attributeId): ?Multiplier
    {
        $values = $this->dogmaAttributeValues->of($structureTypeId, [$attributeId]);
        if (!\array_key_exists($attributeId, $values)) {
            return null;
        }

        return new Multiplier($values[$attributeId]);
    }
}
