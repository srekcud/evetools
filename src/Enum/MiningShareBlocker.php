<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningShareBlocker: string
{
    case NotClosing = 'not_closing';
    case EachForThemselves = 'each_for_themselves';
    case QuantitiesNotKnown = 'quantities_not_known';
    case PricesMissing = 'prices_missing';
    case BoosterMissing = 'booster_missing';
    case FleetStorageRecipientMissing = 'fleet_storage_recipient_missing';
    case FleetStorageExceedsMined = 'fleet_storage_exceeds_mined';
    case NotEachForThemselves = 'not_each_for_themselves';
}
