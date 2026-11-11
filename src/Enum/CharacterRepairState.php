<?php

declare(strict_types=1);

namespace App\Enum;

enum CharacterRepairState: string
{
    case Invalid = 'invalid';
    case MissingScopes = 'missing_scopes';
}
