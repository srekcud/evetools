<?php

declare(strict_types=1);

namespace App\Enum;

enum CorporationRoleStatus: string
{
    case Held = 'held';
    case RoleAbsent = 'role_absent';
    case ReconnectRequired = 'reconnect_required';
    case EsiError = 'esi_error';
}
