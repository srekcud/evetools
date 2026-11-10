<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningSiteLedgerEtaUnknownReason: string
{
    case OperationNotRunning = 'operation_not_running';
    case LaterSite = 'later_site';
    case LedgerUnknown = 'ledger_unknown';
    case NothingMined = 'nothing_mined';
}
