<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

enum HaulLineState: string
{
    case Ready = 'ready';
    case InProduction = 'in_production';
    case InTransit = 'in_transit';
}
