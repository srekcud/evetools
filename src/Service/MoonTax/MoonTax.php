<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

final readonly class MoonTax
{
    /**
     * @param list<array{typeId: int, quantity: int, unitPrice: float, ratePercent: float, tax: float}> $lines
     * @param list<int>                                                                                 $unknownTypeIds
     */
    public function __construct(
        public array $lines,
        public ?float $tax,
        public array $unknownTypeIds,
    ) {
    }
}
