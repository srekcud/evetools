<?php

declare(strict_types=1);

namespace App\Industry\Application;

/**
 * How a production cost is computed (spec R12, D4).
 */
enum CostMode
{
    /** Whole runs, explicit surplus: projects, shopping list. */
    case Plan;
    /** Fractional runs, intermediates paid pro rata of their demand: Profit Margins, scanner. */
    case MarginalCost;
}
