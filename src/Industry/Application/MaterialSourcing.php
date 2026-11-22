<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Quantity;

final readonly class MaterialSourcing
{
    public function __construct(
        public int $typeId,
        public Quantity $quantity,
        public Cost $jitaCost,
        public Cost $importCost,
        public Cost $structureCost,
        public ?float $volumePerUnit,
        public ?int $jumps,
        public SourcingSide $side,
        public bool $structureFallback,
        public ?Isk $saving,
        public Cost $structureBridgeCost,
        public ?int $structureAvailableQuantity = null,
        public ?SourcingSide $chosenMarket = null,
        public ?string $lineId = null,
    ) {
    }

    public function jitaWithImportCost(): Cost
    {
        return $this->jitaCost->plus($this->importCost);
    }

    public function structureDeliveredCost(): Cost
    {
        return $this->structureCost->plus($this->structureBridgeCost);
    }

    public function structureStock(): StructureStock
    {
        if (null === $this->structureAvailableQuantity) {
            return StructureStock::Unknown;
        }

        return $this->structureAvailableQuantity >= $this->quantity->value ? StructureStock::Enough : StructureStock::Insufficient;
    }

    public function market(): SourcingSide
    {
        return $this->chosenMarket ?? $this->side;
    }

    public function bestCost(): Cost
    {
        return $this->costOf($this->side);
    }

    public function selectedCost(): Cost
    {
        return $this->costOf($this->market());
    }

    private function costOf(SourcingSide $side): Cost
    {
        return match ($side) {
            SourcingSide::Jita => $this->jitaWithImportCost(),
            SourcingSide::Structure => $this->structureDeliveredCost(),
            SourcingSide::Unknown => $this->jitaWithImportCost()->plus($this->structureDeliveredCost()),
        };
    }
}
