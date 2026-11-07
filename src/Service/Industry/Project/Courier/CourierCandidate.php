<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final readonly class CourierCandidate
{
    public function __construct(
        public CourierContract $contract,
        public bool $matchesQuote,
    ) {
    }
}
