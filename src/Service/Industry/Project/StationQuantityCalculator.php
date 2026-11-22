<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\CachedIndustryJob;
use App\Entity\IndustryProjectBomLine;

final readonly class StationQuantityCalculator
{
    public const string REASON_PLAN_UNAVAILABLE = 'plan_unavailable';
    public const string REASON_CONSUMPTION_UNKNOWN = 'consumption_unknown';
    public const string REASON_STATION_UNRESOLVED = 'station_unresolved';
    public const string REASON_NO_CONTAINER_AT_STATION = 'no_container_at_station';
    public const string REASON_TRANSFER_UNREADABLE = 'transfer_unreadable';

    private const array JOB_GROUPS = ['component', 'final'];
    private const array STATUSES_IN_PRODUCTION = [CachedIndustryJob::STATUS_ACTIVE, CachedIndustryJob::STATUS_PAUSED, CachedIndustryJob::STATUS_READY];
    private const int UNRESOLVED_STATION_KEY = 0;

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     * @param array<string, array<int, int>>|null $jobMaterialNeeds
     * @param list<ProjectContainerReading> $readings
     * @param array<string, int|null> $locationIdByStructureId
     * @param array<int, string> $stationNameByLocationId stations of the plan, in plan order
     * @param array<int, array<int, int|null>> $inTransitByLocationAndType by arrival location id, by type id
     *
     * @return list<TypeStationQuantities>
     */
    public function compute(array $bomLines, ?array $jobMaterialNeeds, array $readings, array $locationIdByStructureId, array $stationNameByLocationId, array $inTransitByLocationAndType = []): array
    {
        $linesByTypeId = [];
        foreach ($bomLines as $line) {
            $linesByTypeId[$line->getTypeId()][] = $line;
        }

        $unavailableReason = $this->unavailableReasonOf($bomLines, $jobMaterialNeeds);
        $entries = [];
        foreach ($linesByTypeId as $typeId => $lines) {
            usort($lines, static fn (IndustryProjectBomLine $left, IndustryProjectBomLine $right): int => $left->getId()->toRfc4122() <=> $right->getId()->toRfc4122());
            $entries[] = [$typeId, $lines];
        }
        usort($entries, static fn (array $left, array $right): int => [$left[1][0]->getTypeName(), $left[0]] <=> [$right[1][0]->getTypeName(), $right[0]]);

        if (null !== $unavailableReason) {
            return array_map(
                static fn (array $entry): TypeStationQuantities => new TypeStationQuantities($entry[0], $entry[1][0]->getTypeName(), self::lineIdsOf($entry[1]), null, $unavailableReason),
                $entries,
            );
        }

        $demand = $this->demandOf($bomLines, $jobMaterialNeeds ?? [], $locationIdByStructureId);
        $readingsByLocationId = [];
        foreach ($readings as $reading) {
            if (null !== $reading->locationId) {
                $readingsByLocationId[$reading->locationId] = $reading;
            }
        }

        return array_map(
            fn (array $entry): TypeStationQuantities => new TypeStationQuantities(
                $entry[0],
                $entry[1][0]->getTypeName(),
                self::lineIdsOf($entry[1]),
                $this->stationsOf($entry[0], $demand, $readingsByLocationId, $stationNameByLocationId, $inTransitByLocationAndType),
                null,
            ),
            $entries,
        );
    }

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     * @param array<string, array<int, int>>|null $jobMaterialNeeds
     */
    private function unavailableReasonOf(array $bomLines, ?array $jobMaterialNeeds): ?string
    {
        if (null === $jobMaterialNeeds) {
            return self::REASON_PLAN_UNAVAILABLE;
        }

        foreach ($this->jobLinesOf($bomLines) as $line) {
            if (null === StartedJobConsumption::ofLine($line, $jobMaterialNeeds[$line->getId()->toRfc4122()] ?? null)) {
                return self::REASON_CONSUMPTION_UNKNOWN;
            }
        }

        return null;
    }

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     *
     * @return list<IndustryProjectBomLine>
     */
    private function jobLinesOf(array $bomLines): array
    {
        return array_values(array_filter(
            $bomLines,
            static fn (IndustryProjectBomLine $line): bool => $line->isJob() && \in_array($line->getJobGroup(), self::JOB_GROUPS, true),
        ));
    }

    /**
     * @param list<IndustryProjectBomLine> $bomLines
     * @param array<string, array<int, int>> $jobMaterialNeeds
     * @param array<string, int|null> $locationIdByStructureId
     *
     * @return array<int, array<int, array{needed: int, consumed: int, inProduction: int}>> by type id, by station key
     */
    private function demandOf(array $bomLines, array $jobMaterialNeeds, array $locationIdByStructureId): array
    {
        $demand = [];
        foreach ($this->jobLinesOf($bomLines) as $line) {
            $lineId = $line->getId()->toRfc4122();
            $plannedStructureId = $line->getPlannedStructureId();
            $stationKey = null === $plannedStructureId ? self::UNRESOLVED_STATION_KEY : ($locationIdByStructureId[$plannedStructureId] ?? self::UNRESOLVED_STATION_KEY);
            $lineNeeds = $jobMaterialNeeds[$lineId] ?? [];
            $lineConsumption = StartedJobConsumption::ofLine($line, $lineNeeds) ?? [];

            foreach ($lineNeeds as $typeId => $neededQuantity) {
                $demand[$typeId][$stationKey] ??= ['needed' => 0, 'consumed' => 0, 'inProduction' => 0];
                $demand[$typeId][$stationKey]['needed'] += $neededQuantity;
                $demand[$typeId][$stationKey]['consumed'] += $lineConsumption[$typeId] ?? 0;
            }

            $producedInProduction = $this->inProductionOf($line);
            if ($producedInProduction > 0) {
                $demand[$line->getTypeId()][$stationKey] ??= ['needed' => 0, 'consumed' => 0, 'inProduction' => 0];
                $demand[$line->getTypeId()][$stationKey]['inProduction'] += $producedInProduction;
            }
        }

        return $demand;
    }

    private function inProductionOf(IndustryProjectBomLine $line): int
    {
        if (!$line->isManufacturingOrReactionJob()) {
            return 0;
        }

        $lineRuns = $line->getRuns() ?? throw new \UnexpectedValueException(\sprintf('Job line %s has no runs.', $line->getId()->toRfc4122()));
        if (0 === $lineRuns) {
            return 0;
        }

        $activeRuns = 0;
        foreach ($line->getJobMatches() as $jobMatch) {
            if (\in_array($jobMatch->getStatus(), self::STATUSES_IN_PRODUCTION, true)) {
                $activeRuns += $jobMatch->getRuns();
            }
        }

        return intdiv($line->getRequiredQuantity() * min($activeRuns, $lineRuns), $lineRuns);
    }

    /**
     * @param array<int, array<int, array{needed: int, consumed: int, inProduction: int}>> $demand
     * @param array<int, ProjectContainerReading> $readingsByLocationId
     * @param array<int, string> $stationNameByLocationId
     * @param array<int, array<int, int|null>> $inTransitByLocationAndType
     *
     * @return list<StationQuantity>
     */
    private function stationsOf(int $typeId, array $demand, array $readingsByLocationId, array $stationNameByLocationId, array $inTransitByLocationAndType): array
    {
        $stationKeys = [];
        foreach ($demand[$typeId] ?? [] as $stationKey => $figures) {
            if ($figures['needed'] > 0 || $figures['consumed'] > 0 || $figures['inProduction'] > 0) {
                $stationKeys[$stationKey] = true;
            }
        }
        foreach ($readingsByLocationId as $locationId => $reading) {
            if (null !== $reading->contents && $this->rawQuantityOf($reading, $typeId) > 0) {
                $stationKeys[$locationId] = true;
            }
        }

        foreach ($inTransitByLocationAndType as $locationId => $inTransitByTypeId) {
            if (\array_key_exists($typeId, $inTransitByTypeId) && (null === $inTransitByTypeId[$typeId] || $inTransitByTypeId[$typeId] > 0)) {
                $stationKeys[$locationId] = true;
            }
        }

        $orderedKeys = $this->orderedStationKeys(array_keys($stationKeys), array_keys($stationNameByLocationId));

        $stations = [];
        foreach ($orderedKeys as $stationKey) {
            $figures = $demand[$typeId][$stationKey] ?? ['needed' => 0, 'consumed' => 0, 'inProduction' => 0];
            $stations[] = $this->stationOf($stationKey, $figures, $readingsByLocationId[$stationKey] ?? null, $typeId, $stationNameByLocationId, $this->inTransitAt($inTransitByLocationAndType, $stationKey, $typeId));
        }

        return $stations;
    }

    /**
     * @param array{needed: int, consumed: int, inProduction: int} $figures
     * @param array<int, string> $stationNameByLocationId
     */
    private function stationOf(int $stationKey, array $figures, ?ProjectContainerReading $reading, int $typeId, array $stationNameByLocationId, ?int $inTransit): StationQuantity
    {
        if (self::UNRESOLVED_STATION_KEY === $stationKey) {
            return $this->unreadableStation(null, null, $figures, self::REASON_STATION_UNRESOLVED, 0);
        }

        $stationName = $stationNameByLocationId[$stationKey] ?? null;
        if (null === $reading) {
            return $this->unreadableStation($stationKey, $stationName, $figures, self::REASON_NO_CONTAINER_AT_STATION, $inTransit);
        }

        if (null === $reading->contents) {
            return $this->unreadableStation($stationKey, $stationName, $figures, $reading->unknownReason ?? throw new \LogicException(\sprintf('The unknown reading of container %s has no reason.', $reading->containerId)), $inTransit);
        }

        $rawQuantity = $this->rawQuantityOf($reading, $typeId);
        $remaining = max(0, $figures['needed'] - $figures['consumed']);
        $onSite = min($rawQuantity, $remaining);
        $toBring = null === $inTransit ? null : max(0, $remaining - $onSite - $inTransit);

        return new StationQuantity($stationKey, $stationName, $figures['needed'], $figures['consumed'], $figures['inProduction'], $onSite, $rawQuantity - $onSite, $toBring, $inTransit, null === $inTransit ? self::REASON_TRANSFER_UNREADABLE : null);
    }

    /**
     * @param array{needed: int, consumed: int, inProduction: int} $figures
     */
    private function unreadableStation(?int $locationId, ?string $stationName, array $figures, string $reason, ?int $inTransit): StationQuantity
    {
        return new StationQuantity($locationId, $stationName, $figures['needed'], $figures['consumed'], $figures['inProduction'], null, null, null, $inTransit, $reason);
    }

    /**
     * @param array<int, array<int, int|null>> $inTransitByLocationAndType
     */
    private function inTransitAt(array $inTransitByLocationAndType, int $locationId, int $typeId): ?int
    {
        $inTransitByTypeId = $inTransitByLocationAndType[$locationId] ?? [];

        return \array_key_exists($typeId, $inTransitByTypeId) ? $inTransitByTypeId[$typeId] : 0;
    }

    private function rawQuantityOf(ProjectContainerReading $reading, int $typeId): int
    {
        foreach ($reading->contents ?? [] as $content) {
            if ($content->typeId === $typeId) {
                return $content->quantity;
            }
        }

        return 0;
    }

    /**
     * @param list<int> $stationKeys
     * @param list<int> $planLocationIds
     *
     * @return list<int>
     */
    private function orderedStationKeys(array $stationKeys, array $planLocationIds): array
    {
        $rank = static fn (int $stationKey): array => match (true) {
            self::UNRESOLVED_STATION_KEY === $stationKey => [2, 0],
            false !== ($planIndex = array_search($stationKey, $planLocationIds, true)) => [0, $planIndex],
            default => [1, $stationKey],
        };
        usort($stationKeys, static fn (int $left, int $right): int => $rank($left) <=> $rank($right));

        return $stationKeys;
    }

    /**
     * @param list<IndustryProjectBomLine> $lines
     *
     * @return list<string>
     */
    private static function lineIdsOf(array $lines): array
    {
        return array_map(static fn (IndustryProjectBomLine $line): string => $line->getId()->toRfc4122(), $lines);
    }
}
