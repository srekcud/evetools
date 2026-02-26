<?php

declare(strict_types=1);

namespace App\Enum;

enum GroupMemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
