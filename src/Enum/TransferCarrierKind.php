<?php

declare(strict_types=1);

namespace App\Enum;

enum TransferCarrierKind: string
{
    case Courier = 'courier';
    case JumpBridge = 'jump_bridge';
    case Pilot = 'pilot';
}
