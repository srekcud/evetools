<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final class CockpitPhasePresenter
{
    /**
     * @param list<CockpitPhase> $phases
     *
     * @return list<array{
     *     key: string,
     *     state: string,
     *     counter: array{done: int, total: int},
     *     blockedReason: array{code: string, count: int}|null,
     * }>
     */
    public static function of(array $phases): array
    {
        return array_map(
            static fn (CockpitPhase $phase): array => [
                'key' => $phase->key,
                'state' => $phase->state,
                'counter' => ['done' => $phase->counterDone, 'total' => $phase->counterTotal],
                'blockedReason' => $phase->blockedReason === null
                    ? null
                    : ['code' => $phase->blockedReason->code, 'count' => $phase->blockedReason->count],
            ],
            $phases,
        );
    }
}
