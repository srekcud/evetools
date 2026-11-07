<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final readonly class CourierQuote
{
    /**
     * @param list<int> $missingVolumeTypeIds
     */
    public function __construct(
        public ?float $volumeCubicMeters,
        public ?int $collateral,
        public ?int $reward,
        public ?int $jumps,
        public string $quoteStatus,
        public array $missingVolumeTypeIds,
    ) {
    }
}
