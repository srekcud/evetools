<?php

declare(strict_types=1);

namespace App\Enum;

enum AuthStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
}
