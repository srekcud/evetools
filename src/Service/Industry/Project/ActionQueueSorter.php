<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ActionQueueSorter
{
    /**
     * @param list<ActionQueueEntry> $entries
     *
     * @return list<ActionQueueEntry>
     */
    public function sort(array $entries): array
    {
        return $entries;
    }
}
