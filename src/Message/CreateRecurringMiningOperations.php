<?php

declare(strict_types=1);

namespace App\Message;

final readonly class CreateRecurringMiningOperations
{
    public function __construct(
        public string $recurrenceId,
    ) {
    }
}
