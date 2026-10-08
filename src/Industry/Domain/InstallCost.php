<?php

declare(strict_types=1);

namespace App\Industry\Domain;

/**
 * Job install cost and its parts (spec R6, D11a).
 */
final readonly class InstallCost
{
    /** Copying and invention pay on the job cost base, 2 % of the manufacturing EIV of the blueprint concerned. */
    private const float JOB_COST_BASE_RATE = 0.02;

    /** Same SCC for every activity in scope; the July 2025 drop to 2 % only targets ME/TE research (D11a pending for copying). */
    private const float SCC_SURCHARGE_RATE = 0.04;

    private const float ALPHA_CLONE_TAX_RATE = 0.0025;

    private function __construct(
        public Cost $systemCost,
        public Cost $facilityTax,
        public Cost $sccSurcharge,
        public Cost $alphaCloneTax,
        public Cost $total,
    ) {
    }

    /**
     * @param ?float $systemCostIndex null when the ESI has no index for the system: the cost is then unknown, never 0
     */
    public static function forJob(
        ActivityKind $activity,
        Cost $estimatedItemValue,
        ?float $systemCostIndex,
        Multiplier $structureCostModifier,
        float $facilityTaxRate,
        bool $alphaClone,
    ): self {
        if (null !== $systemCostIndex) {
            self::assertRate($systemCostIndex, 'cost index');
        }
        self::assertRate($facilityTaxRate, 'facility tax rate');

        $base = match ($activity) {
            ActivityKind::Manufacturing, ActivityKind::Reaction => $estimatedItemValue,
            ActivityKind::Copying, ActivityKind::Invention => $estimatedItemValue->times(self::JOB_COST_BASE_RATE),
        };

        // The structure cost role bonus applies to the cost index term only, never to the taxes.
        $systemCost = null === $systemCostIndex
            ? $base->plus(Cost::unknown(MissingData::costIndex()))
            : $base->times($systemCostIndex * $structureCostModifier->value);
        $facilityTax = $base->times($facilityTaxRate);
        $sccSurcharge = $base->times(self::SCC_SURCHARGE_RATE);
        $alphaCloneTax = $base->times($alphaClone ? self::ALPHA_CLONE_TAX_RATE : 0.0);

        return new self(
            $systemCost,
            $facilityTax,
            $sccSurcharge,
            $alphaCloneTax,
            $systemCost->plus($facilityTax)->plus($sccSurcharge)->plus($alphaCloneTax),
        );
    }

    private static function assertRate(float $rate, string $name): void
    {
        if (!is_finite($rate) || $rate < 0.0) {
            throw new \InvalidArgumentException(\sprintf('The %s must be finite and not negative, got %F.', $name, $rate));
        }
    }
}
