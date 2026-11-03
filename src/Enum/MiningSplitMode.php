<?php

declare(strict_types=1);

namespace App\Enum;

enum MiningSplitMode: string
{
    case ValueProrata = 'value_prorata';
    case EachForThemselves = 'each_for_themselves';
}
