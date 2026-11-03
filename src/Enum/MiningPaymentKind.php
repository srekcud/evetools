<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningPaymentKind: string
{
    case BoosterCut = 'booster_cut';
    case CorporationCut = 'corporation_cut';
    case CollectedCorporationCut = 'collected_corporation_cut';
}
