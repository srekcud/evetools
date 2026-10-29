<?php

declare(strict_types=1);

namespace App\Enum;

enum CharacterSyncFailureReason: string
{
    case MissingToken = 'missing_token';
    case MissingScope = 'missing_scope';
    case AuthenticationRequired = 'authentication_required';
    case EsiRateLimited = 'esi_rate_limited';
    case EsiUnavailable = 'esi_unavailable';
    case EsiError = 'esi_error';
    case SyncFailed = 'sync_failed';
}
