<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\Cost;

final readonly class DeliveredSourcingTotals
{
    public function __construct(
        public Cost $purchases,
        public Cost $installation,
        public Cost $transport,
    ) {
    }

    public function total(): Cost
    {
        return $this->purchases->plus($this->installation)->plus($this->transport);
    }
}
