<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * An end product of a plan: built for the requested runs with the efficiency of its blueprint.
 */
final readonly class PlanTarget
{
    public function __construct(public int $productTypeId, public Runs $runs, public BlueprintEfficiency $efficiency)
    {
    }
}
