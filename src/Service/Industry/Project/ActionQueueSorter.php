<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ActionQueueSorter
{
    private const array CODE_RANK = [
        'record_sale' => 0,
        'complete_project' => 1,
        'launch_job' => 2,
        'source_material' => 3,
        'start_selling' => 4,
        'review_contributions' => 5,
        'start_project' => 6,
    ];

    private const int BLOCKED_FIRST = 0;
    private const int NOT_BLOCKED = 1;
    private const int NAMED_FIRST = 0;
    private const int UNNAMED_LAST = 1;

    /**
     * @param list<ActionQueueEntry> $entries
     *
     * @return list<ActionQueueEntry>
     */
    public function sort(array $entries): array
    {
        $keyed = array_map(
            fn (ActionQueueEntry $entry): array => [$this->sortKey($entry), $entry],
            $entries,
        );

        usort($keyed, static fn (array $left, array $right): int => self::compareKeys($left[0], $right[0]));

        return array_map(static fn (array $pair): ActionQueueEntry => $pair[1], $keyed);
    }

    /**
     * @param array{int, int, int, string, string} $left
     * @param array{int, int, int, string, string} $right
     */
    private static function compareKeys(array $left, array $right): int
    {
        return [$left[0], $left[1], $left[2]] <=> [$right[0], $right[1], $right[2]]
            ?: strcmp($left[3], $right[3])
            ?: strcmp($left[4], $right[4]);
    }

    /**
     * @return array{int, int, int, string, string}
     */
    private function sortKey(ActionQueueEntry $entry): array
    {
        $code = $entry->nextAction->code;
        if (!array_key_exists($code, self::CODE_RANK)) {
            throw new \UnexpectedValueException(sprintf('Unknown next action code "%s" for project %s', $code, $entry->projectId));
        }

        return [
            $entry->nextAction->blocked ? self::BLOCKED_FIRST : self::NOT_BLOCKED,
            self::CODE_RANK[$code],
            $entry->projectName === null ? self::UNNAMED_LAST : self::NAMED_FIRST,
            $entry->projectName ?? '',
            $entry->projectId,
        ];
    }
}
