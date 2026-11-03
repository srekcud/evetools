<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningFleetStorageDelivery: string
{
    case None = 'none';
    case All = 'all';
    case Partial = 'partial';
}
