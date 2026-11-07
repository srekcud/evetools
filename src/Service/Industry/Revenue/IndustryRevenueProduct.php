<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

final readonly class IndustryRevenueProduct
{
    public function __construct(
        public ?int $typeId,
        public ?string $typeName,
        public int $projectCount,
        public int $unknownProjectCount,
        public float $profit,
    ) {
    }
}
