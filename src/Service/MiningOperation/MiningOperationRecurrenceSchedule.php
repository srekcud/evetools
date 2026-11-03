<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

final readonly class MiningOperationRecurrenceSchedule
{
    private const string WEEK = '+7 days';
    private const string CREATION_LEAD = '-7 days';

    public function nextWeeklyStartAt(\DateTimeImmutable $lastPlannedStartAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $nextStartAt = $lastPlannedStartAt->setTimezone(new \DateTimeZone('UTC'))->modify(self::WEEK);
        while ($nextStartAt < $now) {
            $nextStartAt = $nextStartAt->modify(self::WEEK);
        }

        return $nextStartAt;
    }

    public function isDue(\DateTimeImmutable $nextStartAt, \DateTimeImmutable $now): bool
    {
        return $now >= $nextStartAt->modify(self::CREATION_LEAD);
    }
}
