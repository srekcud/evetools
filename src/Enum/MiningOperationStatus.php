<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningOperationStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Closing = 'closing';
    case Shares = 'shares';
    case Settled = 'settled';
    case Completed = 'completed';

    public function isOpen(): bool
    {
        return $this === self::Planned || $this === self::InProgress || $this === self::Closing;
    }

    public function isArchived(): bool
    {
        return false;
    }

    public function acceptsParticipants(): bool
    {
        return $this === self::Planned || $this === self::InProgress;
    }
}
