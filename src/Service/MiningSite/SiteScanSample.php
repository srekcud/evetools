<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final readonly class SiteScanSample
{
    /**
     * @param array<int, int> $unitsByTypeId units remaining per typeId, present types only
     */
    public function __construct(
        public \DateTimeImmutable $at,
        public array $unitsByTypeId,
    ) {
    }
}
