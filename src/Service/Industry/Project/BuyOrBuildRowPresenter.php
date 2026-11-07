<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\IntermediateComparison;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Service\Industry\MissingDataPresenter;
use App\Service\Industry\PartialPricePresenter;

final class BuyOrBuildRowPresenter
{
    private const int DISPLAYED_DECIMALS = 2;

    /**
     * @return array<string, mixed>
     */
    public static function of(IntermediateComparison $comparison, ?string $typeName): array
    {
        $demand = $comparison->demand->value;
        $buyCost = $comparison->buyCost();
        $allCosts = $comparison->buildCost->plus($buyCost);

        return [
            'typeId' => $comparison->typeId,
            'typeName' => $typeName,
            'activity' => self::activityNameOf($comparison->activity),
            'quantity' => $demand,
            'runs' => $comparison->runs,
            'producedQuantity' => $comparison->quantityProduced->value,
            'surplus' => $comparison->surplus->value,
            'volumePerUnit' => $comparison->volumePerUnit,
            'jumps' => $comparison->jumps,
            'buildPerUnit' => self::perUnit($comparison->buildCost, $demand),
            'buildPerUnitIfSurplusReused' => $comparison->surplus->value > 0
                ? self::perUnit($comparison->buildCost, $comparison->quantityProduced->value)
                : null,
            'jitaPerUnit' => self::perUnit($comparison->jitaCost, $demand),
            'importPerUnit' => self::perUnit($comparison->importCost, $demand),
            'buyPerUnit' => self::perUnit($buyCost, $demand),
            'choice' => $comparison->choice->value,
            'choiceWithoutImport' => $comparison->choiceWithoutImport->value,
            'saving' => self::rounded($comparison->saving),
            'savingIfSurplusReused' => self::rounded($comparison->savingIfSurplusReused),
            'missingData' => MissingDataPresenter::of($allCosts),
            'warnings' => PartialPricePresenter::of($allCosts),
        ];
    }

    private static function activityNameOf(ActivityKind $activity): string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => 'manufacturing',
            ActivityKind::Reaction => 'reaction',
            ActivityKind::Copying, ActivityKind::Invention => throw new \LogicException(\sprintf('A buy or build comparison has no %s activity.', $activity->name)),
        };
    }

    private static function perUnit(Cost $cost, int $units): ?float
    {
        return $cost->isKnown() ? round($cost->amount()->amount / $units, self::DISPLAYED_DECIMALS) : null;
    }

    private static function rounded(?Isk $amount): ?float
    {
        return null === $amount ? null : round($amount->amount, self::DISPLAYED_DECIMALS);
    }
}
