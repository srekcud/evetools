<?php

declare(strict_types=1);

namespace App\Service\Industry\Project\Action;

use App\Service\ESI\CharacterLocation;

final readonly class PlacedActionSorter
{
    private const int RANK_DOCKED = 0;
    private const int RANK_SAME_SOLAR_SYSTEM = 1;
    private const int RANK_OTHER_STATION = 2;
    private const int RANK_UNKNOWN = 3;
    private const int RANK_ANYWHERE = 4;

    private const int NAMED_FIRST = 0;
    private const int UNNAMED_LAST = 1;

    /**
     * @param list<PlacedAction>      $actions
     * @param list<CharacterLocation> $positions
     *
     * @return list<LocatedAction>
     */
    public function sort(array $actions, array $positions): array
    {
        $keyed = array_map(
            fn (PlacedAction $action): array => [$this->sortKey($action, $positions), new LocatedAction($action, $this->hereOf($action->place, $positions))],
            $actions,
        );

        usort($keyed, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return array_map(static fn (array $pair): LocatedAction => $pair[1], $keyed);
    }

    /**
     * @param list<CharacterLocation> $positions
     *
     * @return array{int, string, int, int, int, string, string, string, string}
     */
    private function sortKey(PlacedAction $action, array $positions): array
    {
        return [
            $this->placeRank($action->place, $positions),
            $action->place->name ?? '',
            $action->place->locationId ?? 0,
            $action->code->rank(),
            null === $action->projectName ? self::UNNAMED_LAST : self::NAMED_FIRST,
            $action->projectName ?? '',
            $action->projectId,
            $action->typeName ?? '',
            $action->bomLineId ?? '',
        ];
    }

    /**
     * @param list<CharacterLocation> $positions
     */
    private function placeRank(ActionPlace $place, array $positions): int
    {
        return match ($place->kind) {
            ActionPlace::KIND_ANYWHERE => self::RANK_ANYWHERE,
            ActionPlace::KIND_UNKNOWN => self::RANK_UNKNOWN,
            default => match (true) {
                true === $this->hereOf($place, $positions) => self::RANK_DOCKED,
                $this->isInSolarSystemOfACharacter($place, $positions) => self::RANK_SAME_SOLAR_SYSTEM,
                default => self::RANK_OTHER_STATION,
            },
        };
    }

    /**
     * @param list<CharacterLocation> $positions
     */
    private function hereOf(ActionPlace $place, array $positions): ?bool
    {
        if (ActionPlace::KIND_STATION !== $place->kind || [] === $positions) {
            return null;
        }

        return array_any(
            $positions,
            static fn (CharacterLocation $position): bool => ($position->stationId ?? $position->structureId) === $place->locationId,
        );
    }

    /**
     * @param list<CharacterLocation> $positions
     */
    private function isInSolarSystemOfACharacter(ActionPlace $place, array $positions): bool
    {
        return null !== $place->solarSystemId
            && array_any($positions, static fn (CharacterLocation $position): bool => $position->solarSystemId === $place->solarSystemId);
    }
}
