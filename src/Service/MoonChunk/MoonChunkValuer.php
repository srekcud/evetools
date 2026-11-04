<?php

declare(strict_types=1);

namespace App\Service\MoonChunk;

final readonly class MoonChunkValuer
{
    public const array PARTICIPANT_COUNTS = [4, 6, 8];
    public const string STATUS_KNOWN = 'known';
    public const string STATUS_UNKNOWN = 'unknown';
    public const string REASON_NO_SCAN = 'no_scan';
    public const string REASON_NO_PRICE = 'no_price';
    public const string REASON_NO_VOLUME = 'no_volume';

    /**
     * @param array<int, float> $sharesByTypeId
     * @param array<int, ?float> $unitPricesByTypeId
     * @param array<int, ?float> $unitVolumesByTypeId
     */
    public function value(array $sharesByTypeId, float $volumeM3, array $unitPricesByTypeId, array $unitVolumesByTypeId): MoonChunkValue
    {
        if ($sharesByTypeId === []) {
            return $this->unknown(self::REASON_NO_SCAN);
        }

        foreach (array_keys($sharesByTypeId) as $typeId) {
            if (($unitPricesByTypeId[$typeId] ?? null) === null) {
                return $this->unknown(self::REASON_NO_PRICE);
            }
        }

        foreach (array_keys($sharesByTypeId) as $typeId) {
            $unitVolume = $unitVolumesByTypeId[$typeId] ?? null;
            if ($unitVolume === null || $unitVolume <= 0.0) {
                return $this->unknown(self::REASON_NO_VOLUME);
            }
        }

        $lines = [];
        $totalValue = 0.0;
        foreach ($sharesByTypeId as $typeId => $share) {
            $units = $share * $volumeM3 / $unitVolumesByTypeId[$typeId];
            $value = $units * $unitPricesByTypeId[$typeId];
            $lines[] = ['typeId' => $typeId, 'share' => $share, 'units' => $units, 'value' => $value];
            $totalValue += $value;
        }

        return new MoonChunkValue(
            self::STATUS_KNOWN,
            null,
            $totalValue,
            array_map(
                static fn (int $participants): array => ['participants' => $participants, 'value' => $totalValue / $participants],
                self::PARTICIPANT_COUNTS,
            ),
            $lines,
        );
    }

    private function unknown(string $reason): MoonChunkValue
    {
        return new MoonChunkValue(self::STATUS_UNKNOWN, $reason, null, null, null);
    }
}
