<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final class ViewerEngagedCapital
{
    private const float NO_APPROVED_CONTRIBUTION = 0.0;

    public static function of(DistributionResult $distribution, string $memberId): ?float
    {
        foreach ($distribution->members as $member) {
            if ($member->memberId === $memberId) {
                return $member->totalCostsEngaged;
            }
        }

        return self::NO_APPROVED_CONTRIBUTION;
    }
}
