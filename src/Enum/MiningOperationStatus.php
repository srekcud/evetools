<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningOperationStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Closing = 'closing';
    case Completed = 'completed';

    public function isOpen(): bool
    {
        return false;
    }

    public function acceptsParticipants(): bool
    {
        return false;
    }
}
