<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonCompositionImportReport
{
    /**
     * @param list<array{moonName: string, reason: string}> $rejected
     */
    public function __construct(
        public int $moonsInDatabase = 0,
        public int $matched = 0,
        public int $imported = 0,
        public int $skippedAlreadyScanned = 0,
        public int $notFoundInSheet = 0,
        public array $rejected = [],
    ) {
    }
}
