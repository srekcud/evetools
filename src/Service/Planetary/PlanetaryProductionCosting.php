<?php

declare(strict_types=1);

namespace App\Service\Planetary;

final class PlanetaryProductionCosting
{
    private const string EXTRACTED_TIER = 'P0';
    private const float QUANTITY_PRECISION = 0.005;

    /** @var array<int, array{cost: float, extracted: bool}|null> */
    private array $unitCosts = [];

    /** @var array<int, true> */
    private array $inProgress = [];

    /** @var array<int, float> */
    private array $consumption = [];

    /**
     * @param array<int, array{tier: string, dailyQuantity: float, inputs: array<int, float>}> $items
     * @param array<int, float|null> $purchasePrices
     * @param array<int, float|null> $hubBuyPrices
     */
    public function __construct(
        private readonly array $items,
        private readonly array $purchasePrices,
        private readonly array $hubBuyPrices,
    ) {
        foreach ($items as $item) {
            foreach ($item['inputs'] as $inputTypeId => $consumed) {
                $this->consumption[$inputTypeId] = array_key_exists($inputTypeId, $this->consumption) ? $this->consumption[$inputTypeId] + $consumed : $consumed;
            }
        }
    }

    /**
     * @return array{cost: float, extracted: bool}|null cost of one unit produced, null when a price is unknown
     */
    public function unitCostOf(int $typeId): ?array
    {
        if (array_key_exists($typeId, $this->unitCosts)) {
            return $this->unitCosts[$typeId];
        }
        if (isset($this->inProgress[$typeId]) || !isset($this->items[$typeId]) || $this->items[$typeId]['dailyQuantity'] <= 0.0) {
            return null;
        }

        $this->inProgress[$typeId] = true;
        $item = $this->items[$typeId];
        $cost = 0.0;
        $extracted = false;
        $known = true;
        foreach ($item['inputs'] as $inputTypeId => $consumed) {
            $input = $this->consumedCostOf($inputTypeId);
            if ($input === null) {
                $known = false;
                break;
            }
            $cost += $consumed / $item['dailyQuantity'] * $input['cost'];
            $extracted = $extracted || $input['extracted'];
        }
        unset($this->inProgress[$typeId]);

        return $this->unitCosts[$typeId] = $known ? ['cost' => $cost, 'extracted' => $extracted] : null;
    }

    /**
     * @return array{cost: float, extracted: bool}|null
     */
    private function consumedCostOf(int $typeId): ?array
    {
        $consumed = $this->consumption[$typeId];
        $own = min($this->outputOf($typeId), $consumed);
        $bought = $consumed - $own;

        $total = 0.0;
        $extracted = false;
        if ($own > self::QUANTITY_PRECISION) {
            $ownCost = $this->ownUnitCostOf($typeId);
            if ($ownCost === null) {
                return null;
            }
            $total += $own * $ownCost['cost'];
            $extracted = $ownCost['extracted'];
        }
        if ($bought > self::QUANTITY_PRECISION) {
            $purchasePrice = $this->purchasePrices[$typeId] ?? null;
            if ($purchasePrice === null) {
                return null;
            }
            $total += $bought * $purchasePrice;
        }

        return ['cost' => $total / $consumed, 'extracted' => $extracted];
    }

    /**
     * @return array{cost: float, extracted: bool}|null
     */
    private function ownUnitCostOf(int $typeId): ?array
    {
        if (($this->items[$typeId]['tier'] ?? null) === self::EXTRACTED_TIER) {
            $hubBuy = $this->hubBuyPrices[$typeId] ?? null;

            return $hubBuy === null ? null : ['cost' => $hubBuy, 'extracted' => true];
        }

        return $this->unitCostOf($typeId);
    }

    private function outputOf(int $typeId): float
    {
        return isset($this->items[$typeId]) ? $this->items[$typeId]['dailyQuantity'] : 0.0;
    }
}
