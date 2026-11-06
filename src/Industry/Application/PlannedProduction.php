<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Plan;

final readonly class PlannedProduction
{
    /**
     * @param array<string, StructureChoice> $structureChoices by activity and product typeId, see choiceKey()
     */
    public function __construct(public Plan $plan, private array $structureChoices)
    {
    }

    public function structureChoiceFor(int $productTypeId, ActivityKind $activity): ?StructureChoice
    {
        return $this->structureChoices[self::choiceKey($productTypeId, $activity)] ?? null;
    }

    public static function choiceKey(int $productTypeId, ActivityKind $activity): string
    {
        return $activity->name.':'.$productTypeId;
    }
}
