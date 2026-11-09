<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

enum OpportunitySort: string
{
    case WeeklyProfit = 'week';
    case IskPerSlotHour = 'hour';
}
