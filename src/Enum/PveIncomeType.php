<?php

declare(strict_types=1);

namespace App\Enum;

enum PveIncomeType: string
{
    case LootSale = 'loot_sale';
    case Bounty = 'bounty';
    case Ess = 'ess';
    case Mission = 'mission';
    case LootContract = 'loot_contract';
    case CorpProject = 'corp_project';
    case Other = 'other';
}
