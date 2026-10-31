<?php

declare(strict_types=1);

namespace App\Service\Industry;

final readonly class ManufacturableProduct
{
    public function __construct(
        public int $typeId,
        public string $typeName,
        public bool $isT2,
    ) {
    }
}
