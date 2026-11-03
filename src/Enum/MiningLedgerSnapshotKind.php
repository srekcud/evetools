<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningLedgerSnapshotKind: string
{
    case Start = 'start';
    case End = 'end';
}
