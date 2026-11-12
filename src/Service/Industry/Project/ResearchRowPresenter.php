<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Industry\Application\ResearchJobEstimate;
use App\Industry\Application\ResearchSuggestion;
use App\Industry\Domain\Cost;
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
            'missingData' => self::missingDataOf($suggestion->missingData),
            'net' => self::rounded($suggestion->net),
            'researchCost' => self::rounded($suggestion->researchCost?->amount),
            'runs' => $suggestion->runs,
            'targetLevel' => $suggestion->targetLevel,
            'typeId' => $suggestion->productTypeId,
            'typeName' => $typeName,
            'verdict' => $suggestion->verdict->value,
            'meResearchDuration' => self::durationOf($suggestion->meResearch),
            'teResearchDuration' => null === $suggestion->teResearch ? null : self::durationOf($suggestion->teResearch),
            'teResearchCost' => null === $suggestion->teResearchCost ? null : self::costOf($suggestion->teResearchCost),
        ];
    }

    /**
     * @return array{targetLevel: int, seconds: ?int, missingData: list<array{reason: string, typeId: ?int}>}
     */
    private static function durationOf(ResearchJobEstimate $estimate): array
    {
        return [
            'targetLevel' => $estimate->targetLevel,
            'seconds' => $estimate->duration->isKnown() ? $estimate->duration->jobDuration()->seconds : null,
            'missingData' => self::missingDataOf($estimate->duration->missingData),
        ];
    }

    /**
     * @return array{amount: ?float, missingData: list<array{reason: string, typeId: ?int}>}
     */
    private static function costOf(Cost $cost): array
    {
        return [
            'amount' => $cost->isKnown() ? self::rounded($cost->amount()->amount) : null,
            'missingData' => self::missingDataOf($cost->missingData),
        ];
    }

    /**
     * @param list<MissingData> $missingData
     *
     * @return list<array{reason: string, typeId: ?int}>
     */
    private static function missingDataOf(array $missingData): array
    {
        return array_map(
            static fn (MissingData $item): array => ['reason' => $item->reason, 'typeId' => $item->typeId],
            $missingData,
        );
    }

    private static function rounded(?float $amount): ?float
    {
        return null === $amount ? null : round($amount, self::DISPLAYED_DECIMALS);
    }
}
