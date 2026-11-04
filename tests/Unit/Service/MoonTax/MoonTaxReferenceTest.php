<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Enum\MoonTaxPeriodKind;
use App\Service\MoonTax\MoonTaxPeriod;
use App\Service\MoonTax\MoonTaxReference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonTaxReference::class)]
#[Group('issue-273')]
final class MoonTaxReferenceTest extends TestCase
{
    private const int LOWEST_DRAW = 0;
    private const int HIGHEST_DRAW = 31;

    public function testWeeklyReferenceCarriesTheIsoWeekOfTheFirstAndTheSecondDayOfNovember2026(): void
    {
        $reference = new MoonTaxReference(static fn (int $min, int $max): int => $min);

        self::assertSame(
            ['TAX-2026-W44-AAAA', 'TAX-2026-W45-AAAA'],
            [
                $reference->forPeriod($this->periodContaining(MoonTaxPeriodKind::Weekly, '2026-11-01')),
                $reference->forPeriod($this->periodContaining(MoonTaxPeriodKind::Weekly, '2026-11-02')),
            ],
        );
    }

    public function testWeeklyReferenceUsesTheIsoYearForTheWeekThatStartsInDecember(): void
    {
        $reference = new MoonTaxReference(static fn (int $min, int $max): int => $min);

        self::assertSame('TAX-2026-W01-AAAA', $reference->forPeriod($this->periodContaining(MoonTaxPeriodKind::Weekly, '2025-12-30')));
    }

    public function testMonthlyReferenceCarriesTheYearAndTheMonthOfThePeriod(): void
    {
        $reference = new MoonTaxReference(static fn (int $min, int $max): int => $min);

        self::assertSame('TAX-2026-M11-AAAA', $reference->forPeriod($this->periodContaining(MoonTaxPeriodKind::Monthly, '2026-11-19')));
    }

    public function testSuffixIsFourCharactersDrawnOverThirtyTwoSymbolsWithoutIAndO(): void
    {
        $draws = [];
        $lowest = new MoonTaxReference(static fn (int $min, int $max): int => $min);
        $highest = new MoonTaxReference(static fn (int $min, int $max): int => $max);
        $recording = new MoonTaxReference(static function (int $min, int $max) use (&$draws): int {
            $draws[] = [$min, $max];

            return $min;
        });
        $period = $this->periodContaining(MoonTaxPeriodKind::Weekly, '2026-11-04');

        $recording->forPeriod($period);

        self::assertSame(
            ['TAX-2026-W45-AAAA', 'TAX-2026-W45-9999', array_fill(0, 4, [self::LOWEST_DRAW, self::HIGHEST_DRAW])],
            [$lowest->forPeriod($period), $highest->forPeriod($period), $draws],
        );
    }

    public function testEachDrawPicksTheCharacterAtItsIndex(): void
    {
        $indexes = [0, 7, 8, 31];
        $reference = new MoonTaxReference(static function (int $min, int $max) use (&$indexes): int {
            return array_shift($indexes);
        });

        self::assertSame('TAX-2026-W45-AHJ9', $reference->forPeriod($this->periodContaining(MoonTaxPeriodKind::Weekly, '2026-11-04')));
    }

    public function testDefaultRandomSourceOnlyProducesTheAllowedSymbols(): void
    {
        $reference = new MoonTaxReference();
        $period = $this->periodContaining(MoonTaxPeriodKind::Weekly, '2026-11-04');

        $references = array_map(static fn (int $draw): string => $reference->forPeriod($period), range(1, 200));

        self::assertSame([], array_values(array_filter($references, static fn (string $generated): bool => preg_match('/^TAX-2026-W45-[A-HJ-NP-Z2-9]{4}$/', $generated) !== 1)));
    }

    private function periodContaining(MoonTaxPeriodKind $kind, string $date): MoonTaxPeriod
    {
        return MoonTaxPeriod::containing($kind, new \DateTimeImmutable($date, new \DateTimeZone('UTC')));
    }
}
