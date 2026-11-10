<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningHaulerPlanUnknownReason: string
{
    case OperationNotRunning = 'operation_not_running';
    case NoBoostShip = 'no_boost_ship';
    case NoFleetStorageRecipient = 'no_fleet_storage_recipient';
    case NoActiveSite = 'no_active_site';
    case SiteRateUnknown = 'site_rate_unknown';
    case LedgerUnknown = 'ledger_unknown';
    case NothingDelivered = 'nothing_delivered';
    case CapacityMissingFromSde = 'capacity_missing_from_sde';
    case VolumeMissing = 'volume_missing';
    case CompressionUnknown = 'compression_unknown';
}
