<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonChunkVolumeEstimator
{
    public const int EXTRACTION_RATE_M3_PER_HOUR = 25000;

    private const int SECONDS_PER_HOUR = 3600;

    public function volumeM3(\DateTimeImmutable $extractionStartTime, \DateTimeImmutable $chunkArrivalTime): float
    {
        $extractionSeconds = max(0, $chunkArrivalTime->getTimestamp() - $extractionStartTime->getTimestamp());

        return $extractionSeconds / self::SECONDS_PER_HOUR * self::EXTRACTION_RATE_M3_PER_HOUR;
    }
}
