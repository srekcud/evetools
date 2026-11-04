<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

use App\Enum\MiningSiteEtaUnknownReason;

final class SiteDepletionEstimator
{
    private const float SECONDS_PER_MINUTE = 60.0;
    private const float IRREGULAR_DEVIATION_PERCENT = 10.0;
    private const int MIN_SCANS_FOR_DEVIATION = 3;

    /**
     * @param list<SiteScanSample> $scans
     * @param array<int, ?float>   $unitPrices price of one unit per typeId, null when unknown
     * @param array<int, ?float>   $volumes    volume in m3 of one unit per typeId, null when unknown
     */
    public function estimate(array $scans, array $unitPrices, array $volumes): SiteEstimate
    {
        $types = [];
        foreach ($this->typeIdsOf($scans) as $typeId) {
            $types[$typeId] = $this->estimateType($typeId, $scans, $unitPrices[$typeId] ?? null, $volumes[$typeId] ?? null);
        }

        return $this->estimateSite($types);
    }

    /**
     * @param list<SiteScanSample> $scans
     *
     * @return list<int>
     */
    private function typeIdsOf(array $scans): array
    {
        $typeIds = [];
        foreach ($scans as $scan) {
            foreach (array_keys($scan->unitsByTypeId) as $typeId) {
                $typeIds[$typeId] = $typeId;
            }
        }

        return array_values($typeIds);
    }

    /**
     * @param list<SiteScanSample> $scans
     */
    private function estimateType(int $typeId, array $scans, ?float $unitPrice, ?float $unitVolume): SiteTypeEstimate
    {
        $presences = [];
        foreach ($scans as $index => $scan) {
            if (array_key_exists($typeId, $scan->unitsByTypeId)) {
                $presences[] = ['index' => $index, 'at' => $scan->at, 'units' => $scan->unitsByTypeId[$typeId]];
            }
        }

        $lastPresence = $presences[count($presences) - 1];
        $units = $lastPresence['units'];

        if ($lastPresence['index'] < array_key_last($scans)) {
            return new SiteTypeEstimate(
                typeId: $typeId,
                units: 0,
                volumeM3: 0.0,
                unitPrice: $unitPrice,
                remainingValue: 0.0,
                lastPresentAt: $lastPresence['at'],
                firstAbsentAt: $scans[$lastPresence['index'] + 1]->at,
            );
        }

        $remaining = [
            'typeId' => $typeId,
            'units' => $units,
            'volumeM3' => $unitVolume === null ? null : $units * $unitVolume,
            'unitPrice' => $unitPrice,
            'remainingValue' => $unitPrice === null ? null : $units * $unitPrice,
        ];

        if (count($presences) === 1) {
            return new SiteTypeEstimate(...$remaining, etaUnknownReason: MiningSiteEtaUnknownReason::SingleScan);
        }

        $origin = $presences[0]['at'];
        $points = array_map(
            static fn (array $presence): array => ['seconds' => (float) ($presence['at']->getTimestamp() - $origin->getTimestamp()), 'units' => (float) $presence['units']],
            $presences,
        );

        $previousPoint = $points[count($points) - 2];
        $lastPoint = $points[count($points) - 1];
        $recentRatePerSecond = ($previousPoint['units'] - $lastPoint['units']) / ($lastPoint['seconds'] - $previousPoint['seconds']);
        $recentEta = $recentRatePerSecond > 0 ? $this->instantAt($origin, $lastPoint['seconds'] + $lastPoint['units'] / $recentRatePerSecond) : null;

        $meanSeconds = array_sum(array_column($points, 'seconds')) / count($points);
        $meanUnits = array_sum(array_column($points, 'units')) / count($points);
        $trendRatePerSecond = -$this->slopeOf($points, $meanSeconds, $meanUnits);
        $trendEta = $trendRatePerSecond > 0 ? $this->instantAt($origin, $meanSeconds + $meanUnits / $trendRatePerSecond) : null;

        $maxDeviationUnits = null;
        $maxDeviationPercent = null;
        if (count($points) >= self::MIN_SCANS_FOR_DEVIATION) {
            $maxDeviationUnits = max(array_map(
                static fn (array $point): float => abs($point['units'] - ($meanUnits - $trendRatePerSecond * ($point['seconds'] - $meanSeconds))),
                $points,
            ));
            $maxDeviationPercent = $maxDeviationUnits / $points[0]['units'] * 100;
        }

        return new SiteTypeEstimate(
            ...$remaining,
            recentRate: $recentRatePerSecond * self::SECONDS_PER_MINUTE,
            recentEta: $recentEta,
            trendRate: $trendRatePerSecond * self::SECONDS_PER_MINUTE,
            trendEta: $trendEta,
            maxDeviationUnits: $maxDeviationUnits,
            maxDeviationPercent: $maxDeviationPercent,
            irregular: $maxDeviationPercent !== null && $maxDeviationPercent > self::IRREGULAR_DEVIATION_PERCENT,
            etaUnknownReason: $recentEta === null || $trendEta === null ? MiningSiteEtaUnknownReason::NotDepleting : null,
        );
    }

    /**
     * @param list<array{seconds: float, units: float}> $points
     */
    private function slopeOf(array $points, float $meanSeconds, float $meanUnits): float
    {
        $covariance = 0.0;
        $variance = 0.0;
        foreach ($points as $point) {
            $covariance += ($point['seconds'] - $meanSeconds) * ($point['units'] - $meanUnits);
            $variance += ($point['seconds'] - $meanSeconds) ** 2;
        }

        return $covariance / $variance;
    }

    private function instantAt(\DateTimeImmutable $origin, float $secondsAfterOrigin): \DateTimeImmutable
    {
        return $origin->setTimestamp($origin->getTimestamp() + (int) round($secondsAfterOrigin));
    }

    /**
     * @param array<int, SiteTypeEstimate> $types
     */
    private function estimateSite(array $types): SiteEstimate
    {
        $recentEtas = [];
        $trendEtas = [];
        $notDepletingTypeIds = [];
        $missingPriceTypeIds = [];
        $remainingValue = 0.0;
        $mostProfitableTypeId = null;
        $highestValuePerM3 = null;

        foreach ($types as $typeId => $type) {
            if ($type->recentEta !== null) {
                $recentEtas[] = $type->recentEta;
            }
            if ($type->trendEta !== null) {
                $trendEtas[] = $type->trendEta;
            }
            if ($type->etaUnknownReason === MiningSiteEtaUnknownReason::NotDepleting) {
                $notDepletingTypeIds[] = $typeId;
            }
            if ($type->remainingValue === null) {
                $missingPriceTypeIds[] = $typeId;
            } else {
                $remainingValue += $type->remainingValue;
            }
            if (!$type->isDepleted() && $type->remainingValue !== null && $type->volumeM3 !== null && $type->volumeM3 > 0) {
                $valuePerM3 = $type->remainingValue / $type->volumeM3;
                if ($highestValuePerM3 === null || $valuePerM3 > $highestValuePerM3) {
                    $highestValuePerM3 = $valuePerM3;
                    $mostProfitableTypeId = $typeId;
                }
            }
        }

        return new SiteEstimate(
            types: $types,
            recentEta: $recentEtas === [] ? null : max($recentEtas),
            trendEta: $trendEtas === [] ? null : max($trendEtas),
            notDepletingTypeIds: $notDepletingTypeIds,
            remainingValue: $missingPriceTypeIds === [] ? $remainingValue : null,
            missingPriceTypeIds: $missingPriceTypeIds,
            mostProfitableTypeId: $mostProfitableTypeId,
            allDepleted: $types !== [] && count(array_filter($types, static fn (SiteTypeEstimate $type): bool => $type->isDepleted())) === count($types),
        );
    }
}
