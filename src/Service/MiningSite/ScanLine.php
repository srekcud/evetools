<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final readonly class ScanLine
{
    public function __construct(
        public int $typeId,
        public int $units,
    ) {
    }
}
