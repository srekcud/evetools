<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MinedSinceStart
{
    /**
     * @param array<int, int> $quantitiesByTypeId
     */
    public function __construct(
        public array $quantitiesByTypeId,
        public ?\DateTimeImmutable $lastChangeAt,
    ) {
    }
}
