<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Courier;

use App\Industry\Application\StructureTotalCostComparator;

final readonly class CourierQuoteCalculator
{
    public const int DAYS_TO_COMPLETE = 3;
    public const int EXPIRATION_DAYS = 7;
    public const string STATUS_READY = 'ready';
    public const string STATUS_NO_PURCHASES = 'no_purchases';
    public const string STATUS_VOLUME_UNKNOWN = 'volume_unknown';
    public const string STATUS_WORKSHOP_UNKNOWN = 'workshop_unknown';
    public const string STATUS_ROUTE_UNKNOWN = 'route_unknown';

    private const int PRICE_DECIMALS = 2;
    private const int VOLUME_DECIMALS = 6;

    /**
     * @param list<array{typeId: int, quantity: int, totalPrice: float}> $purchases
     * @param array<int, float|null>                                      $volumeByTypeId
     */
    public function quote(array $purchases, array $volumeByTypeId, bool $hasWorkshopSystem, ?int $jumps): CourierQuote
    {
        $shipped = array_values(array_filter($purchases, static fn (array $purchase): bool => $purchase['quantity'] > 0));
        if ([] === $shipped) {
            return new CourierQuote(null, null, null, null, self::STATUS_NO_PURCHASES, []);
        }

        $collateral = (int) ceil(round(array_sum(array_column($shipped, 'totalPrice')), self::PRICE_DECIMALS));
        $missingVolumeTypeIds = $this->typesWithoutVolume($shipped, $volumeByTypeId);
        if ([] !== $missingVolumeTypeIds) {
            return new CourierQuote(null, $collateral, null, $jumps, self::STATUS_VOLUME_UNKNOWN, $missingVolumeTypeIds);
        }

        $volume = round($this->totalVolume($shipped, $volumeByTypeId), self::VOLUME_DECIMALS);
        if (!$hasWorkshopSystem) {
            return new CourierQuote($volume, $collateral, null, $jumps, self::STATUS_WORKSHOP_UNKNOWN, []);
        }
        if (null === $jumps) {
            return new CourierQuote($volume, $collateral, null, null, self::STATUS_ROUTE_UNKNOWN, []);
        }

        $reward = (int) round($volume * $jumps * StructureTotalCostComparator::TRANSPORT_ISK_PER_CUBIC_METER_JUMP);

        return new CourierQuote($volume, $collateral, $reward, $jumps, self::STATUS_READY, []);
    }

    /**
     * @param list<array{typeId: int, quantity: int, totalPrice: float}> $shipped
     * @param array<int, float|null>                                      $volumeByTypeId
     *
     * @return list<int>
     */
    private function typesWithoutVolume(array $shipped, array $volumeByTypeId): array
    {
        $missing = [];
        foreach ($shipped as $purchase) {
            if (null === ($volumeByTypeId[$purchase['typeId']] ?? null)) {
                $missing[$purchase['typeId']] = $purchase['typeId'];
            }
        }

        return array_values($missing);
    }

    /**
     * @param list<array{typeId: int, quantity: int, totalPrice: float}> $shipped
     * @param array<int, float|null>                                      $volumeByTypeId
     */
    private function totalVolume(array $shipped, array $volumeByTypeId): float
    {
        return array_sum(array_map(
            static fn (array $purchase): float => $volumeByTypeId[$purchase['typeId']] * $purchase['quantity'],
            $shipped,
        ));
    }
}
