<?php

declare(strict_types=1);

namespace App\Industry\Application;

/**
 * How the structure of a job was chosen (spec D3, D3b). BestOutsideFavoriteSystem is shown as a warning.
 */
enum StructureChoiceStatus
{
    case Assigned;
    case BestInFavoriteSystem;
    case BestConfigured;
    case BestOutsideFavoriteSystem;
    case NotConfigured;
}
