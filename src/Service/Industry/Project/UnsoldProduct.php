<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class UnsoldProduct
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public int $unsoldQuantity,
        public ?float $lastUnitPrice,
    ) {}
}
