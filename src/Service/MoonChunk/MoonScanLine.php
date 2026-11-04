<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonScanLine
{
    public function __construct(
        public string $name,
        public float $share,
        public int $lineNumber,
    ) {
    }
}
