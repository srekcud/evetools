<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;

final readonly class StructureCostBreakdown
{
    public function __construct(
        public Cost $materials,
        public Cost $installation,
        public Cost $transport,
        public ?float $transportedCubicMeters,
        public ?int $jumps,
    ) {
    }

    public function total(): Cost
    {
        return $this->materials->plus($this->installation)->plus($this->transport);
    }

    public function plus(self $other): self
    {
        return new self(
            $this->materials->plus($other->materials),
            $this->installation->plus($other->installation),
            $this->transport->plus($other->transport),
            null === $this->transportedCubicMeters || null === $other->transportedCubicMeters ? null : $this->transportedCubicMeters + $other->transportedCubicMeters,
            null === $this->jumps || null === $other->jumps ? null : $this->jumps,
        );
    }
}
