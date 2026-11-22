<?php

declare(strict_types=1);

namespace App\Dto\Transfer;

final readonly class TransferEndpoint
{
    public function __construct(
        public int $solarSystemId,
        public ?int $locationId,
    ) {
    }
}
