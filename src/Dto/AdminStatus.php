<?php

declare(strict_types=1);

namespace App\Dto;

final class AdminStatus
{
    /** @param list<AdminStatusRule> $rules */
    public function __construct(
        public readonly string $level,
        public readonly array $rules,
    ) {
    }
}
