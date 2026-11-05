<?php

declare(strict_types=1);

namespace App\Enum;

enum ParticipantStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Removed = 'removed';
}
