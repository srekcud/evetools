<?php

declare(strict_types=1);

namespace App\Service\ESI;

final readonly class PaginatedEsiResponse
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(
        public array $items,
        public ?\DateTimeImmutable $expiresAt,
    ) {
    }
}
