<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

enum TransfersUnavailableReason: string
{
    case AwaitingSales = 'awaiting_sales';
    case UnsoldProducts = 'unsold_products';
    case UnknownFees = 'unknown_fees';
    case UnknownCost = 'unknown_cost';
    case UnknownSeller = 'unknown_seller';
}
