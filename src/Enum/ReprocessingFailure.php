<?php

declare(strict_types=1);

namespace App\Enum;

enum ReprocessingFailure: string
{
    case MissingToken = 'missing_token';
    case MissingScope = 'missing_scope';
    case EsiError = 'esi_error';
}
