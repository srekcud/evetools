<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\InstallCost;

/**
 * Expected invention cost of the T2 blueprint copies needed by the production (spec R8).
 */
final readonly class InventionCost
{
    /**
     * @param Cost   $attemptCost      datacores + decryptor + invention install + install of the 1-run T1 copy
     * @param ?float $expectedAttempts null when the SDE has no invention probability
     */
    public function __construct(
        public Cost $datacoresCost,
        public Cost $decryptorCost,
        public InstallCost $inventionInstallCost,
        public InstallCost $copyInstallCost,
        public Cost $attemptCost,
        public ?float $expectedAttempts,
        public Cost $total,
    ) {
    }
}
