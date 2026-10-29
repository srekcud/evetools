<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\TimeEfficiency;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class BlueprintEfficiencyLevels
{
    public static function requireValid(int $materialEfficiencyLevel, int $timeEfficiencyLevel): void
    {
        try {
            new MaterialEfficiency($materialEfficiencyLevel);
            new TimeEfficiency($timeEfficiencyLevel);
        } catch (\InvalidArgumentException $refusal) {
            throw new BadRequestHttpException($refusal->getMessage(), $refusal);
        }
    }
}
