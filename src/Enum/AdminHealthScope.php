<?php

declare(strict_types=1);

namespace App\Enum;

enum AdminHealthScope: string
{
    case EsiErrorBudget = 'esi-error-budget';
    case FailedMessages = 'failed-messages';
}
