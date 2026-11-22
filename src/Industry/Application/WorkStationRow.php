<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\MissingData;
use App\Industry\Domain\Multiplier;

final readonly class WorkStationRow
{
    /**
     * @param list<WorkTransportLeg> $transportLegs
     * @param list<MissingData>      $missingData
     */
    public function __construct(
        public ProductionStructure $structure,
        public bool $inFavoriteSystem,
        public Multiplier $materialMultiplier,
        public ?float $materialsCost,
        public ?float $installationCost,
        public ?float $transportCost,
        public array $transportLegs,
        public ?float $totalCost,
        public ?float $delta,
        public array $missingData,
    ) {
    }

    public function withDelta(?float $delta): self
    {
        return new self(
            $this->structure,
            $this->inFavoriteSystem,
            $this->materialMultiplier,
            $this->materialsCost,
            $this->installationCost,
            $this->transportCost,
            $this->transportLegs,
            $this->totalCost,
            $delta,
            $this->missingData,
        );
    }
}
