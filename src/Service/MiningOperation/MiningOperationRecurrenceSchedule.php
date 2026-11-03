<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningOperationRecurrenceSchedule
{
    public function nextWeeklyStartAt(\DateTimeImmutable $lastPlannedStartAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $lastPlannedStartAt;
    }

    public function isDue(\DateTimeImmutable $nextStartAt, \DateTimeImmutable $now): bool
    {
        return false;
    }
}
