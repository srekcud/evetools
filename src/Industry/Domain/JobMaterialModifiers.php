<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Material modifier of a job (structure × rig, security included, spec R4). Structure selection stays in Application (D3).
 */
interface JobMaterialModifiers
{
    public function forJob(Recipe $recipe): Multiplier;
}
