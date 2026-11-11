<?php

declare(strict_types=1);

namespace App\Dto;

final class AdminStatusRule
{
    public function __construct(
        public readonly string $key,
        public readonly string $severity,
        public readonly int $value,
        public readonly ?int $threshold,
        public readonly string $section,
    ) {
    }
}
