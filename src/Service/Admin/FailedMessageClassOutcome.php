<?php

declare(strict_types=1);

namespace App\Service\Admin;

final readonly class FailedMessageClassOutcome
{
    public function __construct(
        public int $processed,
        public int $remaining,
    ) {
    }
}
