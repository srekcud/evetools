<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Enum\MoonTaxPeriodKind;

final readonly class MoonTaxReference
{
    private const string SYMBOLS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int SUFFIX_LENGTH = 4;

    /**
     * @param \Closure(int, int): int|null $randomInt
     */
    public function __construct(private ?\Closure $randomInt = null)
    {
    }

    public function forPeriod(MoonTaxPeriod $period): string
    {
        $label = $period->kind === MoonTaxPeriodKind::Weekly
            ? $period->start->format('o-\WW')
            : $period->start->format('Y-\Mm');

        return sprintf('TAX-%s-%s', $label, $this->suffix());
    }

    private function suffix(): string
    {
        $randomInt = $this->randomInt ?? random_int(...);
        $suffix = '';
        for ($position = 0; $position < self::SUFFIX_LENGTH; ++$position) {
            $suffix .= self::SYMBOLS[$randomInt(0, strlen(self::SYMBOLS) - 1)];
        }

        return $suffix;
    }
}
