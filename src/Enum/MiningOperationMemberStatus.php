<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningOperationMemberStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Removed = 'removed';
}
