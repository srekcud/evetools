<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Enum\MoonTaxPeriodKind;

final readonly class MoonTaxPeriod
{
    public function __construct(
        public MoonTaxPeriodKind $kind,
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    public static function containing(MoonTaxPeriodKind $kind, \DateTimeImmutable $moment): self
    {
        return new self($kind, $moment, $moment);
    }
}
