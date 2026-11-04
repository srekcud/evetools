<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Enum\MoonTaxPeriodKind;
use App\Service\MoonTax\MoonTaxPeriod;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonTaxPeriod::class)]
#[Group('issue-273')]
final class MoonTaxPeriodTest extends TestCase
{
    private const string UTC_FORMAT = 'Y-m-d H:i:s T';

    public function testWeeklyPeriodRunsFromMondayMidnightUtcToTheNextMondayMidnightUtc(): void
    {
        $period = MoonTaxPeriod::containing(MoonTaxPeriodKind::Weekly, new \DateTimeImmutable('2026-11-04 13:45:12', new \DateTimeZone('UTC')));

        self::assertSame(['2026-11-02 00:00:00 UTC', '2026-11-09 00:00:00 UTC'], $this->boundsOf($period));
    }

    public function testWeeklyPeriodIncludesItsStartAndExcludesItsEnd(): void
    {
        $atStart = MoonTaxPeriod::containing(MoonTaxPeriodKind::Weekly, new \DateTimeImmutable('2026-11-02 00:00:00', new \DateTimeZone('UTC')));
        $justBeforeEnd = MoonTaxPeriod::containing(MoonTaxPeriodKind::Weekly, new \DateTimeImmutable('2026-11-08 23:59:59', new \DateTimeZone('UTC')));

        self::assertSame(
            [['2026-11-02 00:00:00 UTC', '2026-11-09 00:00:00 UTC'], ['2026-11-02 00:00:00 UTC', '2026-11-09 00:00:00 UTC']],
            [$this->boundsOf($atStart), $this->boundsOf($justBeforeEnd)],
        );
    }

    public function testWeeklyPeriodBelongsToTheWeekOfTheInstantInUtcNotOfItsOwnOffset(): void
    {
        $period = MoonTaxPeriod::containing(MoonTaxPeriodKind::Weekly, new \DateTimeImmutable('2026-11-02 01:00:00', new \DateTimeZone('+02:00')));

        self::assertSame(['2026-10-26 00:00:00 UTC', '2026-11-02 00:00:00 UTC'], $this->boundsOf($period));
    }

    public function testMonthlyPeriodRunsFromTheFirstToTheFirstOfTheNextMonthInUtc(): void
    {
        $period = MoonTaxPeriod::containing(MoonTaxPeriodKind::Monthly, new \DateTimeImmutable('2026-11-30 23:59:59', new \DateTimeZone('UTC')));

        self::assertSame(['2026-11-01 00:00:00 UTC', '2026-12-01 00:00:00 UTC'], $this->boundsOf($period));
    }

    public function testMonthlyPeriodOfDecemberEndsOnTheFirstOfJanuaryOfTheNextYear(): void
    {
        $period = MoonTaxPeriod::containing(MoonTaxPeriodKind::Monthly, new \DateTimeImmutable('2026-12-31 12:00:00', new \DateTimeZone('UTC')));

        self::assertSame(['2026-12-01 00:00:00 UTC', '2027-01-01 00:00:00 UTC'], $this->boundsOf($period));
    }

    private function boundsOf(MoonTaxPeriod $period): array
    {
        return [
            $period->start->setTimezone(new \DateTimeZone('UTC'))->format(self::UTC_FORMAT),
            $period->end->setTimezone(new \DateTimeZone('UTC'))->format(self::UTC_FORMAT),
        ];
    }
}
