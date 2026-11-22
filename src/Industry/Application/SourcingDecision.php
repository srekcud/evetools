<?php

declare(strict_types=1);

namespace App\Industry\Application;

final readonly class SourcingDecision
{
    /**
     * @param array<string, DeliveredSourcingOption> $options
     */
    public function __construct(
        public array $options,
        public ?SourcingChoice $recommended,
        public ?SourcingChoice $effective,
        public bool $bought,
    ) {
    }
}
