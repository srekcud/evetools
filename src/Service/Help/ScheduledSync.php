<?php

declare(strict_types=1);

namespace App\Service\Help;

final readonly class ScheduledSync
{
    public function __construct(
        public string $messageClass,
        public int $intervalSeconds,
    ) {
    }
}
