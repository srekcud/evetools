<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningCharacterEligibility: string
{
    case Eligible = 'eligible';
    case ReconnectRequired = 'reconnect_required';
    case InAnotherOperation = 'in_another_operation';
}
