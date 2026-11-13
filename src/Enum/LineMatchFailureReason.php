<?php

declare(strict_types=1);

namespace App\Enum;

enum LineMatchFailureReason: string
{
    case ProducerMissingFromSde = 'producer_missing_from_sde';
    case NoRuns = 'no_runs';
    case ContributionNotRefreshed = 'contribution_not_refreshed';
}
