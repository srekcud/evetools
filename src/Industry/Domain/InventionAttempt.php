<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Cost of one invention attempt (spec R8, R6).
 */
final readonly class InventionAttempt
{
    /**
     * The 1-run T1 copy consumed per attempt is not part of it: the Application layer adds it, as it owns the copy job.
     *
     * @param Cost $decryptor known 0 ISK when the attempt uses no decryptor
     */
    public static function cost(Cost $datacores, Cost $decryptor, InstallCost $inventionInstallCost): Cost
    {
        return $datacores->plus($decryptor)->plus($inventionInstallCost->total);
    }
}
