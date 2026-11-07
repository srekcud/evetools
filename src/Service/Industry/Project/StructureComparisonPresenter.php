<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\JobStructureComparison;
use App\Industry\Application\StructureChoiceStatus;
use App\Industry\Application\StructureComparisonRow;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\Cost;
use App\Industry\Domain\Isk;
use App\Service\Industry\MissingDataPresenter;

final class StructureComparisonPresenter
{
    private const int DISPLAYED_DECIMALS = 2;

    private const int DISPLAYED_TAX_DECIMALS = 4;

    private const float PERCENT = 100.0;

    /**
     * @param array<string, array{name: string, kind: string}> $structureLabels by structure id
     * @param array<int, string>                               $solarSystemNames by solar system id
     *
     * @return array<string, mixed>
     */
    public static function of(JobStructureComparison $comparison, ?string $typeName, array $structureLabels, array $solarSystemNames): array
    {
        return [
            'typeId' => $comparison->productTypeId,
            'typeName' => $typeName,
            'activity' => self::activityNameOf($comparison->activity),
            'jobs' => $comparison->jobs,
            'runs' => $comparison->runs,
            'chosenStructureId' => $comparison->chosenStructureId,
            'choiceStatus' => self::statusNameOf($comparison->choiceStatus),
            'cheapestStructureId' => $comparison->cheapestStructureId,
            'savingIfCheapest' => self::rounded($comparison->savingIfCheapest),
            'rows' => array_map(
                static fn (StructureComparisonRow $row): array => self::rowOf($row, $structureLabels, $solarSystemNames),
                $comparison->rows,
            ),
        ];
    }

    /**
     * @param array<string, array{name: string, kind: string}> $structureLabels
     * @param array<int, string>                               $solarSystemNames
     *
     * @return array<string, mixed>
     */
    private static function rowOf(StructureComparisonRow $row, array $structureLabels, array $solarSystemNames): array
    {
        $structure = $row->structure;
        $breakdown = $row->breakdown;
        $total = $breakdown->total();
        $solarSystemId = $structure->solarSystemId;

        return [
            'structureId' => $structure->id,
            'name' => $structureLabels[$structure->id]['name'] ?? null,
            'kind' => $structureLabels[$structure->id]['kind'] ?? null,
            'solarSystemId' => $solarSystemId,
            'solarSystemName' => null === $solarSystemId ? null : ($solarSystemNames[$solarSystemId] ?? null),
            'inFavoriteSystem' => $row->inFavoriteSystem,
            'jumps' => $breakdown->jumps,
            'materialBonusPercent' => round((1.0 - $row->materialMultiplier->value) * self::PERCENT, self::DISPLAYED_DECIMALS),
            'facilityTaxPercent' => round($structure->facilityTaxRate * self::PERCENT, self::DISPLAYED_TAX_DECIMALS),
            'materialsCost' => self::amountOf($breakdown->materials),
            'installationCost' => self::amountOf($breakdown->installation),
            'transportCost' => self::amountOf($breakdown->transport),
            'transportedCubicMeters' => $breakdown->transportedCubicMeters,
            'totalCost' => self::amountOf($total),
            'delta' => null === $row->delta ? null : round($row->delta, self::DISPLAYED_DECIMALS),
            'missingData' => MissingDataPresenter::of($total),
        ];
    }

    private static function amountOf(Cost $cost): ?float
    {
        return $cost->isKnown() ? round($cost->amount()->amount, self::DISPLAYED_DECIMALS) : null;
    }

    private static function rounded(?Isk $amount): ?float
    {
        return null === $amount ? null : round($amount->amount, self::DISPLAYED_DECIMALS);
    }

    private static function activityNameOf(ActivityKind $activity): string
    {
        return match ($activity) {
            ActivityKind::Manufacturing => 'manufacturing',
            ActivityKind::Reaction => 'reaction',
            ActivityKind::Copying, ActivityKind::Invention => throw new \LogicException(\sprintf('A structure comparison has no %s activity.', $activity->name)),
        };
    }

    private static function statusNameOf(StructureChoiceStatus $status): string
    {
        return match ($status) {
            StructureChoiceStatus::Assigned => 'assigned',
            StructureChoiceStatus::BestInFavoriteSystem => 'best_in_favorite_system',
            StructureChoiceStatus::BestConfigured => 'best_configured',
            StructureChoiceStatus::BestOutsideFavoriteSystem => 'best_outside_favorite_system',
            StructureChoiceStatus::BestByTotalCost => 'best_by_total_cost',
            StructureChoiceStatus::NotConfigured => 'not_configured',
        };
    }
}
