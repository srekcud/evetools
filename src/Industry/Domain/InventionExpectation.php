<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Expected invention effort and cost for T2 runs (spec R8): an expectation, never rounded to whole attempts.
 */
final readonly class InventionExpectation
{
    /**
     * @param ?InventionChance $chance null when the SDE has no probability (#74): the costs are then unknown, never 0
     */
    private function __construct(
        private int $t1BlueprintTypeId,
        private ?InventionChance $chance,
        private InventionOutcome $outcome,
        private Cost $attemptCost,
    ) {
    }

    public static function of(int $t1BlueprintTypeId, ?InventionChance $chance, InventionOutcome $outcome, Cost $attemptCost): self
    {
        return new self($t1BlueprintTypeId, $chance, $outcome, $attemptCost);
    }

    public function costPerSuccessfulCopy(): Cost
    {
        if (null === $this->chance) {
            return $this->unknownProbability();
        }

        return $this->attemptCost->times(1 / $this->chance->value);
    }

    /**
     * Quantities never depend on a price (R10): only a missing probability makes them unknown.
     */
    public function expectedAttempts(Runs $wantedT2Runs): ?float
    {
        if (null === $this->chance) {
            return null;
        }

        return $wantedT2Runs->value / ($this->chance->value * $this->outcome->runs->value);
    }

    public function costForRuns(Runs $wantedT2Runs): Cost
    {
        $expectedAttempts = $this->expectedAttempts($wantedT2Runs);
        if (null === $expectedAttempts) {
            return $this->unknownProbability();
        }

        return $this->attemptCost->times($expectedAttempts);
    }

    private function unknownProbability(): Cost
    {
        return Cost::unknown(MissingData::inventionProbability($this->t1BlueprintTypeId))->plus($this->attemptCost);
    }
}
