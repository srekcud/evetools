<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class FailedMessage
{
    public function __construct(
        public string $id,
        public ?string $messageClass,
        public ?string $exceptionClass,
        public ?string $exceptionMessage,
        public ?\DateTimeImmutable $failedAt,
        public ?int $retryCount,
    ) {
    }
}
