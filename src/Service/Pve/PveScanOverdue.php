<?php

declare(strict_types=1);

namespace App\Service\Pve;

final readonly class PveScanOverdue
{
    public const int OVERDUE_AFTER_DAYS = 7;

    public static function isOverdue(?\DateTimeImmutable $lastScanAt, \DateTimeImmutable $now): bool
    {
        if ($lastScanAt === null) {
            return true;
        }

        return $lastScanAt < $now->modify('-' . self::OVERDUE_AFTER_DAYS . ' days');
    }
}
