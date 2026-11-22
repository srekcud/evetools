<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

enum PlacedActionCode: string
{
    case LaunchJob = 'launch_job';
    case SourceMaterial = 'source_material';
    case ReviewContributions = 'review_contributions';
    case ReviewJoinRequests = 'review_join_requests';

    public function rank(): int
    {
        return match ($this) {
            self::LaunchJob => 0,
            self::SourceMaterial => 1,
            self::ReviewContributions => 2,
            self::ReviewJoinRequests => 3,
        };
    }
}
