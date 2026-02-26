<?php

declare(strict_types=1);

namespace App\Enum;

enum GroupMemberStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
}
