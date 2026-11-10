<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\BlueprintEfficiency;

final readonly class OwnedBlueprintUse
{
    public function __construct(public BlueprintEfficiency $efficiency, public bool $original, public ?int $researcherEveCharacterId = null)
    {
    }

    public function isBetterThan(self $other): bool
    {
        return [$this->efficiency->materialEfficiency->value, $this->efficiency->timeEfficiency->value, $this->original]
            > [$other->efficiency->materialEfficiency->value, $other->efficiency->timeEfficiency->value, $other->original];
    }
}
