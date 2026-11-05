<?php

declare(strict_types=1);

namespace App\Enum;

enum ParticipantRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
