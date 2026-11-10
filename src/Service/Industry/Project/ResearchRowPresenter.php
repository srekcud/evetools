<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\ResearchSuggestion;
use App\Industry\Domain\MissingData;

final class ResearchRowPresenter
{
    private const int DISPLAYED_DECIMALS = 2;

    /**
     * @return array<string, mixed>
     */
    public static function of(ResearchSuggestion $suggestion, ?string $typeName): array
    {
        return [
            'currentLevel' => $suggestion->currentLevel,
            'materialSaving' => self::rounded($suggestion->materialSaving?->amount),
            'missingData' => array_map(
                static fn (MissingData $missingData): array => ['reason' => $missingData->reason, 'typeId' => $missingData->typeId],
                $suggestion->missingData,
            ),
            'net' => self::rounded($suggestion->net),
            'researchCost' => self::rounded($suggestion->researchCost?->amount),
            'runs' => $suggestion->runs,
            'targetLevel' => $suggestion->targetLevel,
            'typeId' => $suggestion->productTypeId,
            'typeName' => $typeName,
            'verdict' => $suggestion->verdict->value,
        ];
    }

    private static function rounded(?float $amount): ?float
    {
        return null === $amount ? null : round($amount, self::DISPLAYED_DECIMALS);
    }
}
