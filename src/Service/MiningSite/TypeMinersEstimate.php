<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final readonly class TypeMinersEstimate
{
    /**
     * @param list<SiteLifespanSample> $sites
     */
    public function __construct(
        public int $typeId,
        public array $sites,
        public MinersNeeded $needed,
    ) {
    }
}
