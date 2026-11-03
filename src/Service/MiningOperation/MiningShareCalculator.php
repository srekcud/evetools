<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningShareCalculator
{
    private const int PERCENT_DENOMINATOR = 100;

    /**
     * @param list<?int> $minedValues
     */
    public function split(array $minedValues, int $boosterCutPercent, int $corporationCutPercent): MiningShareSplit
    {
        $participantCount = count($minedValues);
        $knownValues = array_filter($minedValues, static fn (?int $value): bool => $value !== null);

        if (count($knownValues) !== $participantCount) {
            return new MiningShareSplit(null, null, null, array_fill(0, $participantCount, null), array_fill(0, $participantCount, null), null, null, null);
        }

        $totalValue = array_sum($knownValues);
        $participantPercent = self::PERCENT_DENOMINATOR - $boosterCutPercent - $corporationCutPercent;
        $numerators = array_merge(
            array_map(static fn (int $value): int => $value * $participantPercent, $knownValues),
            [$totalValue * $boosterCutPercent, $totalValue * $corporationCutPercent],
        );

        $amounts = array_map(static fn (int $numerator): int => intdiv($numerator, self::PERCENT_DENOMINATOR), $numerators);
        $roundingRemainder = $totalValue - array_sum($amounts);
        $adjustments = array_fill(0, count($numerators), 0);

        $recipientsByLargestFraction = array_keys($numerators);
        usort(
            $recipientsByLargestFraction,
            static fn (int $left, int $right): int => [$numerators[$right] % self::PERCENT_DENOMINATOR, $left] <=> [$numerators[$left] % self::PERCENT_DENOMINATOR, $right],
        );
        foreach (array_slice($recipientsByLargestFraction, 0, $roundingRemainder) as $recipient) {
            ++$amounts[$recipient];
            $adjustments[$recipient] = 1;
        }

        return new MiningShareSplit(
            $totalValue,
            $amounts[$participantCount],
            $amounts[$participantCount + 1],
            array_slice($amounts, 0, $participantCount),
            array_slice($adjustments, 0, $participantCount),
            $adjustments[$participantCount],
            $adjustments[$participantCount + 1],
            $roundingRemainder,
        );
    }
}
