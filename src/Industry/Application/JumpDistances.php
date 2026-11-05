<?php

declare(strict_types=1);

namespace App\Industry\Application;

interface JumpDistances
{
    public function jumpsBetween(int $fromSolarSystemId, int $toSolarSystemId): ?int;
}
