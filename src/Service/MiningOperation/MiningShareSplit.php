<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningShareSplit
{
    /**
     * @param list<?int> $shares
     * @param list<?int> $roundingAdjustments
     */
    public function __construct(
        public ?int $totalValue,
        public ?int $boosterCut,
        public ?int $corporationCut,
        public array $shares,
        public array $roundingAdjustments,
        public ?int $boosterRoundingAdjustment,
        public ?int $corporationRoundingAdjustment,
        public ?int $roundingRemainder,
    ) {
    }
}
