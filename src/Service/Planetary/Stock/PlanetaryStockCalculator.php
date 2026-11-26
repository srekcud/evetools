<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

use App\Service\Industry\Stock\PersonalStockContainerContents;
use App\Service\Industry\Stock\PersonalStockContainerReading;

final readonly class PlanetaryStockCalculator
{
    private const int QUANTITY_DECIMALS = 6;

    public function calculate(PlanetaryStockReadings $readings): PlanetaryStock
    {
        $corpUnknownReason = $this->corpUnknownReason($readings->corporationContainers);
        $corpQuantities = null === $corpUnknownReason ? $this->corpQuantities($readings) : [];

        $rows = [];
        $consumptionKnown = true;
        foreach ($readings->weeklyNeeds as $need) {
            $rows[] = $this->rowOf($need, $readings, null === $corpUnknownReason ? ($corpQuantities[$need->typeId] ?? 0) : null);
            $consumptionKnown = $consumptionKnown && null !== $need->weeklyNetNeed;
        }

        usort($rows, static function (PlanetaryStockRow $left, PlanetaryStockRow $right): int {
            if (null === $left->typeName xor null === $right->typeName) {
                return null === $left->typeName ? 1 : -1;
            }

            return [$left->typeName, $left->typeId] <=> [$right->typeName, $right->typeId];
        });

        return new PlanetaryStock(
            $rows,
            $corpUnknownReason,
            $readings->needsComplete && null !== $readings->homeLocationId && null === $corpUnknownReason && $consumptionKnown,
        );
    }

    private function rowOf(PlanetaryWeeklyNeed $need, PlanetaryStockReadings $readings, ?int $corpQuantity): PlanetaryStockRow
    {
        $homeLocationId = $readings->homeLocationId;
        $home = null === $homeLocationId ? null : ($readings->quantitiesByRootLocation[$homeLocationId][$need->typeId] ?? 0);
        $hub = $readings->hubStructureId === $homeLocationId ? 0 : ($readings->quantitiesByRootLocation[$readings->hubStructureId][$need->typeId] ?? 0);
        $corp = null === $homeLocationId ? null : $corpQuantity;
        $customsOffice = $readings->customsOfficeQuantities[$need->typeId] ?? 0;
        $openOrders = $this->openOrdersOf($need->typeId, $readings);
        $consumption = $need->weeklyNetNeed;

        $coverage = null;
        $coverageWithOrders = null;
        $toBuy = null;
        if (null !== $home && null !== $corp && null !== $consumption) {
            $held = $home + $hub + $corp + $customsOffice;
            if ($consumption > 0.0) {
                $coverage = $held / $consumption;
                $coverageWithOrders = ($held + $openOrders) / $consumption;
                $toBuy = (int) ceil(round(max(0.0, $consumption * $readings->coverageWeeks - ($held + $openOrders)), self::QUANTITY_DECIMALS));
            } else {
                $toBuy = 0;
            }
        }

        return new PlanetaryStockRow(
            $need->typeId,
            $need->typeName,
            $need->volumePerUnit,
            $consumption,
            $home,
            $hub,
            $corp,
            $customsOffice,
            $openOrders,
            $coverage,
            $coverageWithOrders,
            $toBuy,
        );
    }

    private function openOrdersOf(int $typeId, PlanetaryStockReadings $readings): int
    {
        $total = 0;
        foreach ($readings->buyOrders as $order) {
            if ($order->typeId === $typeId && $order->locationId === $readings->hubStructureId) {
                $total += $order->volumeRemain;
            }
        }

        return $total;
    }

    /**
     * @param list<PersonalStockContainerContents> $containers
     */
    private function corpUnknownReason(array $containers): ?string
    {
        foreach ($containers as $container) {
            if (PersonalStockContainerReading::STATUS_UNKNOWN === $container->reading->status) {
                return $container->reading->unknownReason;
            }
        }

        return null;
    }

    /**
     * @return array<int, int>
     */
    private function corpQuantities(PlanetaryStockReadings $readings): array
    {
        $quantities = [];
        foreach ($readings->corporationContainers as $container) {
            if (null === $readings->homeLocationId || $container->reading->stationId !== $readings->homeLocationId) {
                continue;
            }
            foreach ($container->articles as $typeId => $quantity) {
                $quantities[$typeId] = ($quantities[$typeId] ?? 0) + $quantity;
            }
        }

        return $quantities;
    }
}
