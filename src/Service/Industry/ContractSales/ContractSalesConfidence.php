<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

enum ContractSalesConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
}
