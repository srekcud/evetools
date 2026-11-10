<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final class ViewerProfitShare
{
    public const string AWAITING_SALES = 'awaiting_sales';
    public const string PROVISIONAL = 'provisional';
    public const string FINAL = 'final';
    public const string FORECAST = 'forecast';
    public const string UNKNOWN = 'unknown';

    /**
     * @return array{amount: float|null, status: string}
     */
    public static function of(DistributionResult $distribution, string $memberId): array
    {
        if ($distribution->awaitingSales) {
            return ['amount' => null, 'status' => self::AWAITING_SALES];
        }

        foreach ($distribution->members as $member) {
            if ($member->memberId !== $memberId) {
                continue;
            }

            if ($member->profitPart === null) {
                break;
            }

            return [
                'amount' => $member->profitPart,
                'status' => $distribution->unsoldProducts === [] ? self::FINAL : self::PROVISIONAL,
            ];
        }

        return ['amount' => null, 'status' => self::UNKNOWN];
    }

    /**
     * @return array{amount: float|null, status: string}
     */
    public static function forecastOf(DistributionResult $distribution, SellForecast $forecast): array
    {
        if ($forecast->jitaNetRevenue === null || $forecast->remainingCost === null || $distribution->totalProjectCost === null) {
            return ['amount' => null, 'status' => self::AWAITING_SALES];
        }

        return [
            'amount' => $forecast->jitaNetRevenue - $forecast->remainingCost - $distribution->totalProjectCost,
            'status' => self::FORECAST,
        ];
    }
}
