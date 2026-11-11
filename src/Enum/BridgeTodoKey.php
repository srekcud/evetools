<?php

declare(strict_types=1);

namespace App\Enum;

enum BridgeTodoKey: string
{
    case ExpiredExtractors = 'expiredExtractors';
    case ExpiringExtractors = 'expiringExtractors';
    case TriggeredPriceAlerts = 'triggeredPriceAlerts';
}
