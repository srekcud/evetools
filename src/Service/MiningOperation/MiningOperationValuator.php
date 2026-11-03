<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use Psr\Clock\ClockInterface;

final readonly class MiningOperationValuator
{
    public function __construct(
        private MinedQuantityCalculator $quantityCalculator,
        private MiningOperationUnitPrices $unitPrices,
        private MiningShareCalculator $shareCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function value(MiningOperation $operation): MiningOperationValuation
    {
        return new MiningOperationValuation(false, $this->clock->now(), null, [], [], null, null, null, null, [], []);
    }
}
