<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

enum PurchaseOriginKind: string
{
    case Jita = 'jita';
    case Other = 'other';
    case Unknown = 'unknown';
}
