<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

use App\Dto\Transfer\TransferEndpoint;

final readonly class StationTransferRequest
{
    /**
     * @param list<array{typeId: int, quantity: int}> $lines
     */
    public function __construct(
        public TransferEndpoint $from,
        public TransferEndpoint $to,
        public array $lines,
        public ?int $carrierCharacterId,
        public ?int $contractId,
    ) {
    }
}
