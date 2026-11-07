<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final readonly class CourierOverview
{
    /**
     * @param list<CourierLine>      $lines
     * @param list<CourierCandidate> $candidates
     */
    public function __construct(
        public CourierQuote $quote,
        public int $originSolarSystemId,
        public ?string $originSolarSystemName,
        public ?int $workshopSolarSystemId,
        public ?string $workshopSolarSystemName,
        public bool $canLink,
        public ?CourierLinkedContract $contract,
        public array $lines,
        public array $candidates,
    ) {
    }
}
