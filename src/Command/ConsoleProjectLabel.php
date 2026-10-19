<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IndustryProject;
use App\Service\UnknownTypeName;

final class ConsoleProjectLabel
{
    public static function of(IndustryProject $project, ?string $displayName): string
    {
        return $displayName ?? UnknownTypeName::label($project->getProductTypeId());
    }
}
