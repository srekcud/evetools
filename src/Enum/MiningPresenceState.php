<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningPresenceState: string
{
    case Online = 'online';
    case Afk = 'afk';
    case Left = 'left';
    case Unknown = 'unknown';
}
