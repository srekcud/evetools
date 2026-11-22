<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

enum StationTransferState: string
{
    case AwaitingDeparture = 'awaiting_departure';
    case InTransit = 'in_transit';
    case Arrived = 'arrived';
    case Unknown = 'unknown';
}
