<?php

declare(strict_types=1);

namespace App\Service;

final class OrderVolumeDecreases
{
    /**
     * @param array<int, array{typeId: int, volumeRemain: int}> $previous
     * @param array<int, array{typeId: int, volumeRemain: int}> $current
     *
     * @return array<int, int>
     */
    public function between(array $previous, array $current): array
    {
        return [];
    }
}
