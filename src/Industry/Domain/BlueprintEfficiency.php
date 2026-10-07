<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class BlueprintEfficiency
{
    public function __construct(public MaterialEfficiency $materialEfficiency, public TimeEfficiency $timeEfficiency)
    {
    }
}
