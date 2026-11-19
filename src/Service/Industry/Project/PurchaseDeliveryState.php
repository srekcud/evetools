<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

enum PurchaseDeliveryState: string
{
    case Missing = 'missing';
    case Partial = 'partial';
    case Delivered = 'delivered';
    case Unknown = 'unknown';
}
