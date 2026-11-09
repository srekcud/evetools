<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

enum OpportunityMarket: string
{
    case All = 'all';
    case Jita = 'jita';
    case Capital = 'capital';
}
