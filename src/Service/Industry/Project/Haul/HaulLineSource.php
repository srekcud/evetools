<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Haul;

enum HaulLineSource: string
{
    case Purchase = 'purchase';
    case Project = 'project';
    case PersonalStock = 'personal_stock';
    case Transfer = 'transfer';
}
