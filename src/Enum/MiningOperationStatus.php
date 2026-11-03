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
        return $this !== self::Completed;
    }

    public function acceptsParticipants(): bool
    {
        return $this === self::Planned || $this === self::InProgress;
    }
}
