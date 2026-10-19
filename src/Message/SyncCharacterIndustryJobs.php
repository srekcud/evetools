<?php

declare(strict_types=1);

namespace App\Message;

final readonly class SyncCharacterIndustryJobs
{
    public function __construct(
        public string $characterId,
    ) {
    }
}
