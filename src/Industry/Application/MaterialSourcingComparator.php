<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\MissingData;

final readonly class MaterialSourcingComparator
{
    public function __construct(
        private MarketPrices $marketPrices,
        private StructureMarketPrices $structureMarketPrices,
        private ItemVolumes $itemVolumes,
        private JumpDistances $jumpDistances,
    ) {
    }

    /**
     * @param list<MaterialToSource> $materials
     *
     * @return list<MaterialSourcing>
     */
    public function compare(array $materials, ?int $structureId, FavoriteSystems $favoriteSystems, ?int $structureSolarSystemId = null): array
    {
        if ([] === $materials) {
            return [];
        }

        $quantitiesByTypeId = [];
        foreach ($materials as $material) {
            $quantitiesByTypeId[$material->typeId] = (float) $material->quantity->value;
        }
        $jitaPrices = $this->marketPrices->unitPricesFor($quantitiesByTypeId);
        $typeIds = array_map(static fn (MaterialToSource $material): int => $material->typeId, $materials);
        $structurePrices = null === $structureId ? null : $this->structureMarketPrices->unitPricesFor($structureId, $typeIds);
        $structureSellVolumes = null === $structureId ? [] : $this->structureMarketPrices->sellVolumesFor($structureId, $typeIds);
        $structureSystemId = null === $structureId ? null : $structureSolarSystemId;

        return array_map(
            fn (MaterialToSource $material): MaterialSourcing => $this->sourcingOf(
                $material,
                $jitaPrices[$material->typeId] ?? null,
                $favoriteSystems,
                $structurePrices,
                $structureSellVolumes,
                $structureSystemId,
            ),
            $materials,
        );
    }

    /**
     * @param array<int, Isk>|null $structurePrices
     * @param array<int, int>      $structureSellVolumes
     */
    private function sourcingOf(MaterialToSource $material, ?WeightedPrice $jitaPrice, FavoriteSystems $favoriteSystems, ?array $structurePrices, array $structureSellVolumes, ?int $structureSystemId): MaterialSourcing
    {
        $typeId = $material->typeId;
        $quantity = $material->quantity->value;
        $volumePerUnit = $this->itemVolumes->volumeOf($typeId);
        $workshopSystemId = $favoriteSystems->forActivity($material->consumedBy);
        $jumps = null === $workshopSystemId ? null : $this->jumpDistances->jumpsBetween(JitaPurchase::JITA_SOLAR_SYSTEM_ID, $workshopSystemId);

        $jitaCost = JitaPurchase::priceCostOf($typeId, $quantity, $jitaPrice);
        $importCost = JitaPurchase::importCostOf($typeId, $quantity, $volumePerUnit, $workshopSystemId, $favoriteSystems->courierHub);
        $jitaWithImportCost = $jitaCost->plus($importCost);
        $structureCost = self::structureCostOf($typeId, $quantity, $structurePrices);
        $structureBridgeCost = Delivery::bridgeCostOf($typeId, $quantity, $volumePerUnit, $structureSystemId, $workshopSystemId, $favoriteSystems->courierHub);
        $structureDeliveredCost = $structureCost->plus($structureBridgeCost);
        $structureAvailableQuantity = $structureSellVolumes[$typeId] ?? null;
        $side = self::sideOf($jitaWithImportCost, $structureDeliveredCost, null !== $structureAvailableQuantity && $structureAvailableQuantity >= $quantity);

        return new MaterialSourcing(
            $typeId,
            $material->quantity,
            $jitaCost,
            $importCost,
            $structureCost,
            $volumePerUnit,
            $jumps,
            $side,
            null !== $structurePrices && !$structureCost->isKnown() && SourcingSide::Jita === $side,
            self::savingOf($jitaWithImportCost, $structureDeliveredCost),
            $structureBridgeCost,
            $structureAvailableQuantity,
            $material->chosenMarket,
            $material->lineId,
        );
    }

    /**
     * @param array<int, Isk>|null $structurePrices
     */
    private static function structureCostOf(int $typeId, int $quantity, ?array $structurePrices): Cost
    {
        $unitPrice = $structurePrices[$typeId] ?? null;

        return null === $unitPrice
            ? Cost::unknown(MissingData::structurePrice($typeId))
            : Cost::known(new Isk($quantity * $unitPrice->amount));
    }

    private static function sideOf(Cost $jitaWithImportCost, Cost $structureDeliveredCost, bool $structureHasEnoughStock): SourcingSide
    {
        $structureIsEligible = $structureDeliveredCost->isKnown() && $structureHasEnoughStock;
        if ($jitaWithImportCost->isKnown() && $structureIsEligible) {
            return $jitaWithImportCost->amount()->isAtMost($structureDeliveredCost->amount()) ? SourcingSide::Jita : SourcingSide::Structure;
        }
        if ($jitaWithImportCost->isKnown()) {
            return SourcingSide::Jita;
        }

        return $structureIsEligible ? SourcingSide::Structure : SourcingSide::Unknown;
    }

    private static function savingOf(Cost $jitaWithImportCost, Cost $structureDeliveredCost): ?Isk
    {
        if (!$jitaWithImportCost->isKnown() || !$structureDeliveredCost->isKnown()) {
            return null;
        }

        return new Isk(abs($jitaWithImportCost->amount()->amount - $structureDeliveredCost->amount()->amount));
    }
}
