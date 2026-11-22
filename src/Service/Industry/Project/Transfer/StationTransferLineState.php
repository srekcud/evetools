<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

final readonly class StationTransferLineState
{
    public const string SOURCE_ASSETS = 'assets';
    public const string SOURCE_DECLARED = 'declared';
    public const string SOURCE_PENDING = 'pending';

    public function __construct(
        public int $typeId,
        public string $typeName,
        public int $quantity,
        public ?float $volume,
        public ?int $departed,
        public ?int $arrived,
        public ?int $inTransit,
        public string $departureSource,
        public string $arrivalSource,
        public ?string $unknownReason,
    ) {
    }
}
