<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

final readonly class MoonTaxReference
{
    /**
     * @param \Closure(int, int): int|null $randomInt
     */
    public function __construct(private ?\Closure $randomInt = null)
    {
    }

    public function forPeriod(MoonTaxPeriod $period): string
    {
        return '';
    }
}
