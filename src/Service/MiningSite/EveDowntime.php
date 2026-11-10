<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final class EveDowntime
{
    private const int HOUR_UTC = 11;

    public function nextAfter(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $downtimeToday = $instant->setTimezone(new \DateTimeZone('UTC'))->setTime(self::HOUR_UTC, 0);

        return $downtimeToday > $instant ? $downtimeToday : $downtimeToday->modify('+1 day');
    }
}
