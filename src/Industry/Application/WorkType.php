<?php

declare(strict_types=1);

namespace App\Industry\Application;

enum WorkType: string
{
    case Reaction = 'reaction';
    case Components = 'components';
    case Hulls = 'hulls';
    case FinalProducts = 'final_products';
    case Blueprints = 'blueprints';
}
