<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonChunkValue
{
    /**
     * @param list<array{participants: int, value: float}>|null $perParticipant
     * @param list<array{typeId: int, share: float, units: float, value: float}>|null $lines
     */
    public function __construct(
        public string $status,
        public ?string $reason,
        public ?float $totalValue,
        public ?array $perParticipant,
        public ?array $lines,
    ) {
    }
}
