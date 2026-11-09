<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

enum CapacityLimit: string
{
    case Market = 'market';
    case Capital = 'capital';
    case Slots = 'slots';
}
