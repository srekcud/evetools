<?php

declare(strict_types=1);

namespace App\Enum;

enum CharacterTokenState: string
{
    case NoToken = 'no_token';
    case Invalid = 'invalid';
    case Expiring = 'expiring';
    case Valid = 'valid';
}
