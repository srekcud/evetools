<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningSiteEtaUnknownReason: string
{
    case NotDepleting = 'not_depleting';
    case SingleScan = 'single_scan';
}
