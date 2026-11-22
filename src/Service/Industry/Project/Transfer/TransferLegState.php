<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

final readonly class TransferLegState
{
    public const string REASON_VOLUME_UNKNOWN = 'volume_unknown';

    /**
     * @param list<StationTransferLineState> $lines
     */
    public function __construct(
        public string $transferId,
        public string $routeId,
        public int $legIndex,
        public ?int $toLocationId,
        public StationTransferState $state,
        public array $lines,
        public ?float $volume,
        public ?string $unknownReason,
        public \DateTimeImmutable $expectedArrivalAt,
    ) {
    }
}
