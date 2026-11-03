<?php

declare(strict_types=1);

namespace App\Enum;

enum ReprocessingRigTier: string
{
    case None = 'none';
    case T1 = 't1';
    case T2 = 't2';
}
