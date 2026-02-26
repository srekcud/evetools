<?php

declare(strict_types=1);

namespace App\Enum;

enum ContributionType: string
{
    case Material = 'material';
    case JobInstall = 'job_install';
    case Bpc = 'bpc';
    case LineRental = 'line_rental';
}
