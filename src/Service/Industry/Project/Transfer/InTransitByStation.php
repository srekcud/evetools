<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Transfer;

final class InTransitByStation
{
    /**
     * @param list<TransferLegState> $legStates every leg of the project
     *
     * @return array<int, array<int, int|null>> by arrival location id, by type id
     */
    public static function of(array $legStates): array
    {
        $legsByRouteId = [];
        foreach ($legStates as $legState) {
            $legsByRouteId[$legState->routeId][$legState->legIndex] = $legState;
        }

        $inTransit = [];
        foreach ($legsByRouteId as $legs) {
            ksort($legs);
            $firstLeg = $legs[array_key_first($legs)];
            $lastLeg = $legs[array_key_last($legs)];
            $stationLocationId = $lastLeg->toLocationId;
            if (null === $stationLocationId) {
                continue;
            }

            foreach ($lastLeg->lines as $lastLine) {
                $routeInTransit = self::inTransitOf($lastLine, self::departedOf($firstLeg, $lastLine->typeId));
                $inTransit[$stationLocationId][$lastLine->typeId] = \array_key_exists($lastLine->typeId, $inTransit[$stationLocationId] ?? [])
                    ? self::sum($inTransit[$stationLocationId][$lastLine->typeId], $routeInTransit)
                    : $routeInTransit;
            }
        }

        return $inTransit;
    }

    private static function departedOf(TransferLegState $firstLeg, int $typeId): ?int
    {
        foreach ($firstLeg->lines as $line) {
            if ($line->typeId === $typeId) {
                return $line->departed;
            }
        }

        throw new \LogicException(\sprintf('The first leg of route %s has no line for type %d.', $firstLeg->routeId, $typeId));
    }

    private static function inTransitOf(StationTransferLineState $lastLine, ?int $departed): ?int
    {
        if ($lastLine->quantity === $lastLine->arrived) {
            return 0;
        }

        if (null === $departed || null === $lastLine->arrived) {
            return null;
        }

        return max(0, $departed - $lastLine->arrived);
    }

    private static function sum(?int $total, ?int $routeInTransit): ?int
    {
        return null === $total || null === $routeInTransit ? null : $total + $routeInTransit;
    }
}
