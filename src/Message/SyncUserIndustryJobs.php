<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncUserIndustryJobs
{
    public function __construct(
        public string $userId,
    ) {
    }
}
