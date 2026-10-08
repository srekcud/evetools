<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Isk;

/**
 * ESI adjusted prices, the base of the EIV (spec R5). Implemented outside the Application.
 */
interface AdjustedPrices
{
    /**
     * Null when the ESI publishes no adjusted price for the type.
     */
    public function adjustedPriceOf(int $typeId): ?Isk;
}
