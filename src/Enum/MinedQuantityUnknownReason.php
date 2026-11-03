<?php

declare(strict_types=1);

namespace App\Enum;

enum MinedQuantityUnknownReason: string
{
    case ReconnectRequired = 'reconnect_required';
    case ReadFailed = 'read_failed';
    case OutOfWindow = 'out_of_window';
    case LedgerInconsistent = 'ledger_inconsistent';
}
