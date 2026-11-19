<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pve;

use App\Service\Pve\PveScanOverdue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PveScanOverdue::class)]
#[Group('issue-1079')]
final class PveScanOverdueTest extends TestCase
{
    private const string NOW = '2026-11-19 12:00:00';

    #[DataProvider('scanAges')]
    public function testAScanIsOverdueWhenItNeverRanOrRanMoreThanSevenDaysAgo(?string $lastScanAt, bool $expected): void
    {
        $lastScan = $lastScanAt === null ? null : new \DateTimeImmutable($lastScanAt);

        $this->assertSame($expected, PveScanOverdue::isOverdue($lastScan, new \DateTimeImmutable(self::NOW)));
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function scanAges(): iterable
    {
        yield 'never ran' => [null, true];
        yield 'ran just now' => [self::NOW, false];
        yield 'six days and twenty three hours ago' => ['2026-11-12 13:00:00', false];
        yield 'exactly seven days ago' => ['2026-11-12 12:00:00', false];
        yield 'seven days and one second ago' => ['2026-11-12 11:59:59', true];
        yield 'a month ago' => ['2026-10-19 12:00:00', true];
    }
}
