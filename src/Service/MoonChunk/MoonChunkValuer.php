<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonChunkValuer
{
    public const array PARTICIPANT_COUNTS = [4, 6, 8];

    /**
     * @param array<int, float> $sharesByTypeId
     * @param array<int, ?float> $unitPricesByTypeId
     * @param array<int, ?float> $unitVolumesByTypeId
     */
    public function value(array $sharesByTypeId, float $volumeM3, array $unitPricesByTypeId, array $unitVolumesByTypeId): MoonChunkValue
    {
        return new MoonChunkValue('unknown', null, null, null, null);
    }
}
