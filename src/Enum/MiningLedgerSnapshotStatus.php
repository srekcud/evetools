<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningLedgerSnapshotStatus: string
{
    case Scheduling = 'scheduling';
    case Waiting = 'waiting';
    case Taken = 'taken';
    case ReconnectRequired = 'reconnect_required';
    case Failed = 'failed';
    case OutOfWindow = 'out_of_window';

    public function isSettled(): bool
    {
        return match ($this) {
            self::Scheduling, self::Waiting => false,
            self::Taken, self::ReconnectRequired, self::Failed, self::OutOfWindow => true,
        };
    }
}
