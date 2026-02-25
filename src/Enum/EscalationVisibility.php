<?php

declare(strict_types=1);

namespace App\Enum;

enum EscalationVisibility: string
{
    case Perso = 'perso';
    case Corp = 'corp';
    case Alliance = 'alliance';
    case Public = 'public';
}
