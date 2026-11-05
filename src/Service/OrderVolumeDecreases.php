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
        $decreases = [];

        foreach ($current as $orderId => $order) {
            if (!isset($previous[$orderId]) || $previous[$orderId]['typeId'] !== $order['typeId']) {
                continue;
            }

            $decrease = $previous[$orderId]['volumeRemain'] - $order['volumeRemain'];
            if ($decrease > 0) {
                $decreases[$order['typeId']] = ($decreases[$order['typeId']] ?? 0) + $decrease;
            }
        }

        return $decreases;
    }
}
