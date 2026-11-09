<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class PlannedTransfer
{
    public function __construct(
        public string $fromParticipantId,
        public string $toParticipantId,
        public int $amountCents,
    ) {
    }
}
