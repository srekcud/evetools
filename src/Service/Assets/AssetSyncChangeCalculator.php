<?php

declare(strict_types=1);

namespace App\Service\Assets;

use App\Dto\AssetQuantityChange;
use App\Entity\CachedAsset;

class AssetSyncChangeCalculator
{
    private const string BEFORE = 'before';
    private const string AFTER = 'after';

    /**
     * @param iterable<CachedAsset> $before
     * @param iterable<CachedAsset> $after
     *
     * @return list<AssetQuantityChange>
     */
    public function calculate(iterable $before, iterable $after): array
    {
        $stocks = [];
        foreach ($before as $asset) {
            $this->accumulate($stocks, $asset, self::BEFORE);
        }
        foreach ($after as $asset) {
            $this->accumulate($stocks, $asset, self::AFTER);
        }

        $changes = [];
        foreach ($stocks as $stock) {
            $delta = $stock[self::AFTER] - $stock[self::BEFORE];
            if ($delta === 0) {
                continue;
            }
            $changes[] = new AssetQuantityChange(
                $stock['typeId'],
                $stock['typeName'],
                $stock['locationId'],
                $stock['locationName'],
                $stock['divisionName'],
                $delta,
            );
        }

        return $changes;
    }

    /**
     * @param array<string, array{typeId: int, typeName: ?string, locationId: int, locationName: string, divisionName: ?string, before: int, after: int}> $stocks
     * @param self::BEFORE|self::AFTER $side
     */
    private function accumulate(array &$stocks, CachedAsset $asset, string $side): void
    {
        $key = $asset->getLocationId() . '|' . ($asset->getDivisionName() ?? '') . '|' . $asset->getTypeId();
        $known = $stocks[$key] ?? null;

        $stocks[$key] = [
            'typeId' => $asset->getTypeId(),
            'typeName' => $asset->getTypeName() ?? $known['typeName'] ?? null,
            'locationId' => $asset->getLocationId(),
            'locationName' => $asset->getLocationName(),
            'divisionName' => $asset->getDivisionName(),
            self::BEFORE => ($known[self::BEFORE] ?? 0) + ($side === self::BEFORE ? $asset->getQuantity() : 0),
            self::AFTER => ($known[self::AFTER] ?? 0) + ($side === self::AFTER ? $asset->getQuantity() : 0),
        ];
    }
}
