<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonChunkVolumeEstimator
{
    public const int EXTRACTION_RATE_M3_PER_HOUR = 25000;

    public function volumeM3(\DateTimeImmutable $extractionStartTime, \DateTimeImmutable $chunkArrivalTime): float
    {
        return -1.0;
    }
}
