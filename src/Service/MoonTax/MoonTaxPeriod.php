<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Enum\MoonTaxPeriodKind;

final readonly class MoonTaxPeriod
{
    private const int DAYS_IN_WEEK = 7;

    public function __construct(
        public MoonTaxPeriodKind $kind,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public static function containing(MoonTaxPeriodKind $kind, \DateTimeImmutable $moment): self
    {
        $day = $moment->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);

        if ($kind === MoonTaxPeriodKind::Weekly) {
            $start = $day->modify(sprintf('-%d days', (int) $day->format('N') - 1));

            return new self($kind, $start, $start->modify(sprintf('+%d days', self::DAYS_IN_WEEK)));
        }

        $start = $day->setDate((int) $day->format('Y'), (int) $day->format('n'), 1);

        return new self($kind, $start, $start->modify('+1 month'));
    }
}
