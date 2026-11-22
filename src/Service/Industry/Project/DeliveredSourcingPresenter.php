<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\DeliveredSourcing;
use App\Industry\Application\DeliveredSourcingLine;
use App\Industry\Application\DeliveredSourcingOption;
use App\Industry\Application\SourcingChoice;
use App\Industry\Application\WorkType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Service\Industry\MissingDataPresenter;

final class DeliveredSourcingPresenter
{
    private const int DISPLAYED_DECIMALS = 2;

    /**
     * @return array<string, mixed>
     */
    public static function rowOf(DeliveredSourcingLine $line, ?string $typeName): array
    {
        $options = [];
        foreach ([SourcingChoice::Build, SourcingChoice::Jita, SourcingChoice::Structure] as $choice) {
            $option = $line->option($choice);
            if (null === $option && SourcingChoice::Build === $choice) {
                continue;
            }
            $options[$choice->value] = self::optionOf($line, $option);
        }

        return [
            'typeId' => $line->typeId,
            'typeName' => $typeName,
            'stage' => self::stageOf($line->workType),
            'activity' => null === $line->activity ? null : self::activityNameOf($line->activity),
            'quantity' => $line->quantity->value,
            'runs' => $line->runs,
            'surplus' => $line->surplus->value,
            'consumerStructureIds' => $line->consumerStructureIds,
            'options' => $options,
            'recommended' => $line->recommended?->value,
            'chosen' => $line->chosen?->value,
            'bought' => $line->bought,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function totalsOf(DeliveredSourcing $sourcing): array
    {
        $totals = $sourcing->totals;
        $total = $totals->total();

        return [
            'purchases' => self::rounded($totals->purchases),
            'installation' => self::rounded($totals->installation),
            'transport' => self::rounded($totals->transport),
            'total' => self::rounded($total),
            'missingData' => MissingDataPresenter::of($total),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function optionOf(DeliveredSourcingLine $line, ?DeliveredSourcingOption $option): array
    {
        if (null === $option) {
            return [
                'total' => null,
                'perUnit' => null,
                'price' => null,
                'installation' => null,
                'transport' => null,
                'eligible' => false,
                'availableQuantity' => null,
                'structureId' => null,
                'missingData' => [],
            ];
        }

        $share = $line->quantity->value / $line->universeDemand->value;

        return [
            'total' => self::rounded($option->total->times($share)),
            'perUnit' => self::rounded($option->total->times(1 / $line->universeDemand->value)),
            'price' => self::rounded($option->price->times($share)),
            'installation' => SourcingChoice::Build === $option->kind ? self::rounded($option->installation->times($share)) : null,
            'transport' => self::rounded($option->transport->times($share)),
            'eligible' => $option->eligible,
            'availableQuantity' => $option->availableQuantity,
            'structureId' => $option->structureId,
            'missingData' => MissingDataPresenter::of($option->total),
        ];
    }

    private static function activityNameOf(ActivityKind $activity): string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => 'manufacturing',
            ActivityKind::Reaction => 'reaction',
            ActivityKind::Copying, ActivityKind::Invention, ActivityKind::ResearchMaterial, ActivityKind::ResearchTime => throw new \LogicException(\sprintf('A sourcing line has no %s activity.', $activity->name)),
        };
    }

    private static function stageOf(?WorkType $workType): string
    {
        return match ($workType) {
            null => 'base',
            WorkType::Reaction => 'reaction',
            WorkType::Hulls => 'hull',
            WorkType::Components, WorkType::FinalProducts, WorkType::Blueprints => 'component',
        };
    }

    private static function rounded(Cost $cost): ?float
    {
        return $cost->isKnown() ? round($cost->amount()->amount, self::DISPLAYED_DECIMALS) : null;
    }
}
