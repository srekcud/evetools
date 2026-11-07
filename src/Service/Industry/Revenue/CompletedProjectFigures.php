<?php

declare(strict_types=1);

namespace App\Service\Industry\Revenue;

final readonly class CompletedProjectFigures
{
    public function __construct(
        public \DateTimeImmutable $completedAt,
        public ?int $productTypeId,
        public ?string $productTypeName,
        public bool $hasMultipleProducts,
        public ?float $profit,
        public ?float $capital,
        public ?float $slotHours,
    ) {
    }
}
