<?php

declare(strict_types=1);

namespace App\Enum;

enum MoonTaxSyncFailure: string
{
    case NoAccountantToken = 'no_accountant_token';
    case ReconnectRequired = 'reconnect_required';
    case EsiError = 'esi_error';
}
