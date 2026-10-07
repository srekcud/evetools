<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Security of the system hosting a job. Wormhole space uses NullSec, labelled "Nullsec / WH" (D6).
 */
enum SecurityClass
{
    case HighSec;
    case LowSec;
    case NullSec;
}
