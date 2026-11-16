<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Enum\ContributionValueSource;

final readonly class ContributionValue
{
    /**
     * @param list<PaidTransactionUnits> $matches
     */
    public function __construct(
        public ?float $value,
        public bool $valuedAtJitaPrice = false,
        public int $awaitingQuantity = 0,
        public ?ContributionValueSource $source = null,
        public array $matches = [],
    ) {
    }
}
