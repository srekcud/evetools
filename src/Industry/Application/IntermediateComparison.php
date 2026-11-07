<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Industry\Domain\Quantity;

final readonly class IntermediateComparison
{
    public function __construct(
        public int $typeId,
        public ActivityKind $activity,
        public int $runs,
        public Quantity $demand,
        public Quantity $quantityProduced,
        public Quantity $surplus,
        public Cost $buildCost,
        public Cost $jitaCost,
        public Cost $importCost,
        public ?float $volumePerUnit,
        public ?int $jumps,
        public BuyOrBuildChoice $choice,
        public BuyOrBuildChoice $choiceWithoutImport,
        public ?Isk $saving,
        public ?Isk $savingIfSurplusReused,
    ) {
    }

    public function buyCost(): Cost
    {
        return $this->jitaCost->plus($this->importCost);
    }
}
