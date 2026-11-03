<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Enum\MinedQuantityState;
use App\Enum\MiningFleetStorageDelivery;

final readonly class MiningFleetStorageKeeping
{
    public function __construct(
        public ?int $keptValue,
        public ?int $deliveredValue,
    ) {
    }

    /**
     * @param array<int, int>|null $deliveredQuantitiesByTypeId
     * @param array<int, ?float>   $unitPricesByTypeId
     */
    public static function of(MinedQuantity $quantity, ?int $minedValue, MiningFleetStorageDelivery $delivery, ?array $deliveredQuantitiesByTypeId, array $unitPricesByTypeId): self
    {
        if ($minedValue === null) {
            return self::unknown();
        }

        return match ($delivery) {
            MiningFleetStorageDelivery::None => new self($minedValue, 0),
            MiningFleetStorageDelivery::All => new self(0, $minedValue),
            MiningFleetStorageDelivery::Partial => self::partial($quantity, $minedValue, $deliveredQuantitiesByTypeId ?? throw new \LogicException('A partial delivery declares its quantities'), $unitPricesByTypeId),
        };
    }

    /**
     * @param array<int, int>    $deliveredQuantitiesByTypeId
     * @param array<int, ?float> $unitPricesByTypeId
     */
    private static function partial(MinedQuantity $quantity, int $minedValue, array $deliveredQuantitiesByTypeId, array $unitPricesByTypeId): self
    {
        if ($quantity->state !== MinedQuantityState::Known || self::deliversMoreThanMined($quantity, $deliveredQuantitiesByTypeId)) {
            return self::unknown();
        }

        $keptValue = 0.0;
        foreach ($quantity->quantitiesByTypeId as $typeId => $minedQuantity) {
            $keptQuantity = $minedQuantity - ($deliveredQuantitiesByTypeId[$typeId] ?? 0);
            if ($keptQuantity === 0) {
                continue;
            }
            $unitPrice = $unitPricesByTypeId[$typeId] ?? null;
            if ($unitPrice === null) {
                return self::unknown();
            }
            $keptValue += $keptQuantity * $unitPrice;
        }

        $roundedKeptValue = (int) round($keptValue);

        return new self($roundedKeptValue, $minedValue - $roundedKeptValue);
    }

    /**
     * @param array<int, int> $deliveredQuantitiesByTypeId
     */
    public static function deliversMoreThanMined(MinedQuantity $quantity, array $deliveredQuantitiesByTypeId): bool
    {
        foreach ($deliveredQuantitiesByTypeId as $typeId => $deliveredQuantity) {
            if ($deliveredQuantity > ($quantity->quantitiesByTypeId[$typeId] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    private static function unknown(): self
    {
        return new self(null, null);
    }
}
