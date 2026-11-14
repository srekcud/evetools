<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ContributionValue
{
    public function __construct(
        public float $value,
        public bool $valuedAtJitaPrice = false,
    ) {
    }
}
