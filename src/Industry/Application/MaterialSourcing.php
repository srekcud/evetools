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
    ) {
    }

    public function jitaWithImportCost(): Cost
    {
        return $this->jitaCost->plus($this->importCost);
    }

    public function bestCost(): Cost
    {
        return match ($this->side) {
            SourcingSide::Jita => $this->jitaWithImportCost(),
            SourcingSide::Structure => $this->structureCost,
            SourcingSide::Unknown => $this->jitaWithImportCost()->plus($this->structureCost),
        };
    }
}
