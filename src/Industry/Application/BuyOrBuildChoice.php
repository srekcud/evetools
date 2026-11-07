<?php

declare(strict_types=1);

namespace App\Industry\Application;

enum BuyOrBuildChoice: string
{
    case Build = 'build';
    case Buy = 'buy';
    case Unknown = 'unknown';
}
