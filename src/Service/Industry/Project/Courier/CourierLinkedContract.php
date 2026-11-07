<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

final readonly class CourierLinkedContract
{
    public function __construct(
        public int $contractId,
        public int $characterId,
        public CourierContractState $state,
        public ?string $unknownReason,
        public ?float $volume,
        public ?float $reward,
        public ?float $collateral,
        public ?int $startLocationId,
        public ?int $endLocationId,
        public ?\DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $linkedAt,
    ) {
    }
}
