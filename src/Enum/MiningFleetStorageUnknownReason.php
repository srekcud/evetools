<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningFleetStorageUnknownReason: string
{
    case OperationNotEnded = 'operation_not_ended';
    case OperationArchived = 'operation_archived';
    case NoContainerName = 'no_container_name';
    case NoCorporation = 'no_corporation';
    case NotCorporationMember = 'not_corporation_member';
    case CorporationAssetsNotSynced = 'corporation_assets_not_synced';
    case NoVisibleDivision = 'no_visible_division';
    case AssetsSyncedBeforeEnd = 'assets_synced_before_end';
    case ContainerNotFound = 'container_not_found';
    case DeliveredQuantityUnknown = 'delivered_quantity_unknown';
}
