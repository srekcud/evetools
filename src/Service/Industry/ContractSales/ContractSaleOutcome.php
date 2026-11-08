<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

enum ContractSaleOutcome: string
{
    case Listed = 'listed';
    case Pending = 'pending';
    case Expired = 'expired';
    case Republished = 'republished';
    case ProbablySold = 'probably_sold';
}
