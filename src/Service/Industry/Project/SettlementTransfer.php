<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class SettlementTransfer
{
    public function __construct(
        public string $fromParticipantId,
        public ?string $fromName,
        public string $toParticipantId,
        public ?string $toName,
        public ?string $toUserId,
        public int $amountCents,
        public ?\DateTimeImmutable $receivedAt,
    ) {
    }

    public function isReceived(): bool
    {
        return $this->receivedAt !== null;
    }
}
