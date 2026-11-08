<?php

declare(strict_types=1);

namespace App\Industry\Domain;

enum LineRentalCategory: string
{
    case ComponentT1 = 'component_t1';
    case ModuleT1 = 'module_t1';
    case ShipT1 = 'ship_t1';
    case ModuleT2 = 'module_t2';
    case ShipT2 = 'ship_t2';
    case CapitalT1 = 'capital_t1';
    case CapitalT2 = 'capital_t2';
}
