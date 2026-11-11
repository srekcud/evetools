<?php

declare(strict_types=1);

namespace App\Service\Industry;

enum SellQuoteReading
{
    case WithEsiFallback;
    case CachedOnly;
}
