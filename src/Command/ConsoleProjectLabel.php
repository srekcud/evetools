<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IndustryProject;

final class ConsoleProjectLabel
{
    public static function of(IndustryProject $project, ?string $displayName): string
    {
        return $displayName ?? "type {$project->getProductTypeId()} (name missing from the SDE)";
    }
}
