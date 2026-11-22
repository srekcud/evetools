<?php

declare(strict_types=1);

namespace App\Industry\Application;

enum WorkTransportKind: string
{
    case Courier = 'courier';
    case JumpBridge = 'jump_bridge';
    case ToHub = 'to_hub';
}
