<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

use App\Entity\User;

class IndustryRevenueReader
{
    public function __construct(
        private readonly CompletedProjectFiguresLoader $loader,
        private readonly IndustryRevenueAggregator $aggregator,
    ) {
    }

    public function reportFor(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): IndustryRevenueReport
    {
        return new IndustryRevenueReport(new IndustryRevenueTotals(0.0, 0, 0, 0.0, null, null), [], []);
    }
}
