<?php

declare(strict_types=1);

namespace App\Enum;

enum IndustryActivityType: int
{
    case Manufacturing = 1;
    case ResearchTime = 3;
    case ResearchMaterial = 4;
    case Copying = 5;
    case Invention = 8;
    case Reaction = 11;
}
