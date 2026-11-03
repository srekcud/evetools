<?php

declare(strict_types=1);

namespace App\Enum;

enum CorporationMiningSyncFailure: string
{
    case NoStationManagerToken = 'no_station_manager_token';
    case EsiError = 'esi_error';
}
