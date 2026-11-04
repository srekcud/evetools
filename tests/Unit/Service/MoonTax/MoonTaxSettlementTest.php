<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\MoonTax;

use App\Entity\CorporationWalletDonation;
use App\Entity\MoonTaxStatement;
use App\Service\MoonTax\MoonTaxSettlement;
use App\Service\MoonTax\MoonTaxSettlementLine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MoonTaxSettlement::class)]
#[Group('issue-273')]
final class MoonTaxSettlementTest extends TestCase
{
    private const int CORPORATION = 98_000_001;
    private const int OTHER_CORPORATION = 98_000_002;
    private const int ILYRA = 2_112_000_001;
    private const int ILYRA_ALT = 2_112_000_009;
    private const int AUREL = 2_112_000_002;
    private const int ROCK_HOLLOW = 1_035_000_000_101;
    private const int DEEP_CORE = 1_035_000_000_102;

    private const string FIRST_WEEK = 'TAX-2026-W44-AAAA';
    private const string SECOND_WEEK = 'TAX-2026-W45-AAAA';

    private MoonTaxSettlement $settlement;

    protected function setUp(): void
    {
        $this->settlement = new MoonTaxSettlement();
    }

    public function testPartialPaymentLeavesTheRemainderThenTheSecondDonationSettlesTheStatement(): void
    {
        $statement = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 6_000_000.0);
        $firstDonation = $this->donation(1, self::ILYRA, 4_000_000.0, '2026-11-03 10:00:00');
        $secondDonation = $this->donation(2, self::ILYRA, 2_000_000.0, '2026-11-03 18:00:00');
        $now = $this->at('2026-11-04 00:00:00');

        $afterFirst = $this->settlement->settle([$firstDonation], [$statement], $now);
        $afterSecond = $this->settlement->settle([$firstDonation, $secondDonation], [$statement], $now);

        self::assertSame(
            [[self::FIRST_WEEK => [4_000_000.0, 2_000_000.0, 'partial']], [self::FIRST_WEEK => [6_000_000.0, 0.0, 'paid']]],
            [$this->outcomesOf($afterFirst), $this->outcomesOf($afterSecond)],
        );
    }

    public function testSurplusOfADonationFlowsToTheNextStatementOfTheSamePayer(): void
    {
        $statements = [
            $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 6_000_000.0),
            $this->weeklyStatement(self::SECOND_WEEK, '2026-11-02', 3_000_000.0),
        ];

        $result = $this->settlement->settle([$this->donation(1, self::ILYRA, 7_000_000.0, '2026-11-10 08:00:00')], $statements, $this->at('2026-11-10 09:00:00'));

        self::assertSame(
            [self::FIRST_WEEK => [6_000_000.0, 0.0, 'paid'], self::SECOND_WEEK => [1_000_000.0, 2_000_000.0, 'partial']],
            $this->outcomesOf($result),
        );
    }

    public function testDonationFeedsTheOldestStatementFirstWhateverTheOrderOfTheInput(): void
    {
        $statements = [
            $this->weeklyStatement(self::SECOND_WEEK, '2026-11-02', 3_000_000.0),
            $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0),
        ];

        $result = $this->settlement->settle([$this->donation(1, self::ILYRA, 3_000_000.0, '2026-11-10 08:00:00')], $statements, $this->at('2026-11-10 09:00:00'));

        self::assertSame(
            [self::FIRST_WEEK => [3_000_000.0, 0.0, 'paid'], self::SECOND_WEEK => [0.0, 3_000_000.0, 'due']],
            $this->outcomesOf($result),
        );
    }

    public function testStatementsOfTheSamePeriodAreFedByAscendingRefineryId(): void
    {
        $statements = [
            $this->weeklyStatement('TAX-2026-W44-DDDD', '2026-10-26', 3_000_000.0, self::DEEP_CORE),
            $this->weeklyStatement('TAX-2026-W44-RRRR', '2026-10-26', 3_000_000.0, self::ROCK_HOLLOW),
        ];

        $result = $this->settlement->settle([$this->donation(1, self::ILYRA, 5_000_000.0, '2026-11-03 08:00:00')], $statements, $this->at('2026-11-04 00:00:00'));

        self::assertSame(
            ['TAX-2026-W44-DDDD' => [2_000_000.0, 1_000_000.0, 'partial'], 'TAX-2026-W44-RRRR' => [3_000_000.0, 0.0, 'paid']],
            $this->outcomesOf($result),
        );
    }

    public function testDonationBeforeThePeriodStartDoesNotPayTheStatementButALaterOneDoes(): void
    {
        $statement = $this->weeklyStatement(self::SECOND_WEEK, '2026-11-02', 6_000_000.0);
        $before = $this->donation(1, self::ILYRA, 6_000_000.0, '2026-11-01 23:59:59');
        $after = $this->donation(2, self::ILYRA, 6_000_000.0, '2026-11-02 00:00:00');
        $now = $this->at('2026-11-03 00:00:00');

        self::assertSame(
            [[self::SECOND_WEEK => [0.0, 6_000_000.0, 'due']], [self::SECOND_WEEK => [6_000_000.0, 0.0, 'paid']]],
            [$this->outcomesOf($this->settlement->settle([$before], [$statement], $now)), $this->outcomesOf($this->settlement->settle([$after], [$statement], $now))],
        );
    }

    public function testSurplusOfADonationNeverPaysAStatementWhosePeriodStartsAfterIt(): void
    {
        $statements = [
            $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0),
            $this->weeklyStatement(self::SECOND_WEEK, '2026-11-02', 3_000_000.0),
        ];

        $result = $this->settlement->settle([$this->donation(1, self::ILYRA, 9_000_000.0, '2026-10-30 12:00:00')], $statements, $this->at('2026-11-04 00:00:00'));

        self::assertSame(
            [self::FIRST_WEEK => [3_000_000.0, 0.0, 'paid'], self::SECOND_WEEK => [0.0, 3_000_000.0, 'due']],
            $this->outcomesOf($result),
        );
    }

    public function testDonationOfAnAltAttributedByHandCountsForTheMinerAndNotForTheAlt(): void
    {
        $statements = [
            $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0, self::ROCK_HOLLOW, self::ILYRA),
            $this->weeklyStatement('TAX-2026-W44-BBBB', '2026-10-26', 3_000_000.0, self::ROCK_HOLLOW, self::ILYRA_ALT),
        ];
        $attributed = $this->donation(1, self::ILYRA_ALT, 3_000_000.0, '2026-11-03 08:00:00')->setAttributedCharacterId(self::ILYRA);

        $result = $this->settlement->settle([$attributed], $statements, $this->at('2026-11-04 00:00:00'));

        self::assertSame(
            [self::FIRST_WEEK => [3_000_000.0, 0.0, 'paid'], 'TAX-2026-W44-BBBB' => [0.0, 3_000_000.0, 'due']],
            $this->outcomesOf($result),
        );
    }

    public function testDonationOfAnAltWithoutAttributionDoesNotCountForTheMiner(): void
    {
        $statement = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0, self::ROCK_HOLLOW, self::ILYRA);

        $result = $this->settlement->settle([$this->donation(1, self::ILYRA_ALT, 3_000_000.0, '2026-11-03 08:00:00')], [$statement], $this->at('2026-11-04 00:00:00'));

        self::assertSame([self::FIRST_WEEK => [0.0, 3_000_000.0, 'due']], $this->outcomesOf($result));
    }

    public function testDonationsOfAnotherPayerAreNeverUsed(): void
    {
        $statement = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0, self::ROCK_HOLLOW, self::ILYRA);

        $result = $this->settlement->settle([$this->donation(1, self::AUREL, 3_000_000.0, '2026-11-03 08:00:00')], [$statement], $this->at('2026-11-04 00:00:00'));

        self::assertSame([self::FIRST_WEEK => [0.0, 3_000_000.0, 'due']], $this->outcomesOf($result));
    }

    public function testDonationToAnotherCorporationIsNeverUsed(): void
    {
        $statement = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0);
        $donationToAnotherCorporation = $this->donation(1, self::ILYRA, 3_000_000.0, '2026-11-03 08:00:00')->setCorporationId(self::OTHER_CORPORATION);

        $result = $this->settlement->settle([$donationToAnotherCorporation], [$statement], $this->at('2026-11-04 00:00:00'));

        self::assertSame([self::FIRST_WEEK => [0.0, 3_000_000.0, 'due']], $this->outcomesOf($result));
    }

    public function testReasonOfADonationNeverChangesWhichStatementItPays(): void
    {
        $statements = [
            $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0),
            $this->weeklyStatement(self::SECOND_WEEK, '2026-11-02', 3_000_000.0),
        ];
        $donation = $this->donation(1, self::ILYRA, 3_000_000.0, '2026-11-10 08:00:00')->setReason(self::SECOND_WEEK);

        $result = $this->settlement->settle([$donation], $statements, $this->at('2026-11-10 09:00:00'));

        self::assertSame(
            [self::FIRST_WEEK => [3_000_000.0, 0.0, 'paid'], self::SECOND_WEEK => [0.0, 3_000_000.0, 'due']],
            $this->outcomesOf($result),
        );
    }

    public function testStatementMarkedPaidByHandIsPaidAndTakesNothingFromTheDonations(): void
    {
        $markedPaid = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0)->setMarkedPaidAt($this->at('2026-11-03 09:00:00'));
        $open = $this->weeklyStatement(self::SECOND_WEEK, '2026-11-02', 3_000_000.0);

        $result = $this->settlement->settle([$this->donation(1, self::ILYRA, 3_000_000.0, '2026-11-10 08:00:00')], [$markedPaid, $open], $this->at('2026-11-10 09:00:00'));

        self::assertSame(
            [self::FIRST_WEEK => 'paid', self::SECOND_WEEK => 'paid'],
            array_map(static fn (MoonTaxSettlementLine $line): string => $line->status->value, $result),
        );
        self::assertSame(3_000_000.0, $result[self::SECOND_WEEK]->paid);
    }

    public function testCancelledStatementIsCancelledAndTakesNothingFromTheDonations(): void
    {
        $cancelled = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 3_000_000.0)->setCancelledAt($this->at('2026-11-03 09:00:00'));
        $open = $this->weeklyStatement(self::SECOND_WEEK, '2026-11-02', 3_000_000.0);

        $result = $this->settlement->settle([$this->donation(1, self::ILYRA, 3_000_000.0, '2026-11-10 08:00:00')], [$cancelled, $open], $this->at('2026-11-20 09:00:00'));

        self::assertSame(
            [self::FIRST_WEEK => 'cancelled', self::SECOND_WEEK => 'paid'],
            array_map(static fn (MoonTaxSettlementLine $line): string => $line->status->value, $result),
        );
    }

    public function testStatementWithAnUnknownTaxIsUnknownAndTakesNothingFromTheDonations(): void
    {
        $unknown = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', null);
        $open = $this->weeklyStatement(self::SECOND_WEEK, '2026-11-02', 3_000_000.0);

        $result = $this->settlement->settle([$this->donation(1, self::ILYRA, 3_000_000.0, '2026-11-10 08:00:00')], [$unknown, $open], $this->at('2026-11-20 09:00:00'));

        self::assertSame(
            [self::FIRST_WEEK => 'unknown', self::SECOND_WEEK => 'paid'],
            array_map(static fn (MoonTaxSettlementLine $line): string => $line->status->value, $result),
        );
    }

    public function testUnpaidStatementIsDueUntilTheGraceDaysAreOverThenLate(): void
    {
        $statement = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 6_000_000.0);

        $atTheLimit = $this->settlement->settle([], [$statement], $this->at('2026-11-09 00:00:00'));
        $oneMinuteLater = $this->settlement->settle([], [$statement], $this->at('2026-11-09 00:01:00'));

        self::assertSame(
            [[self::FIRST_WEEK => [0.0, 6_000_000.0, 'due']], [self::FIRST_WEEK => [0.0, 6_000_000.0, 'late']]],
            [$this->outcomesOf($atTheLimit), $this->outcomesOf($oneMinuteLater)],
        );
    }

    public function testPartiallyPaidStatementBecomesLateAfterTheGraceDays(): void
    {
        $statement = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 6_000_000.0);
        $donation = $this->donation(1, self::ILYRA, 1_000_000.0, '2026-11-03 08:00:00');

        $result = $this->settlement->settle([$donation], [$statement], $this->at('2026-11-09 00:01:00'));

        self::assertSame([self::FIRST_WEEK => [1_000_000.0, 5_000_000.0, 'late']], $this->outcomesOf($result));
    }

    public function testPaidStatementStaysPaidAfterTheGraceDays(): void
    {
        $statement = $this->weeklyStatement(self::FIRST_WEEK, '2026-10-26', 6_000_000.0);
        $donation = $this->donation(1, self::ILYRA, 6_000_000.0, '2026-11-03 08:00:00');

        $result = $this->settlement->settle([$donation], [$statement], $this->at('2026-12-24 00:00:00'));

        self::assertSame([self::FIRST_WEEK => [6_000_000.0, 0.0, 'paid']], $this->outcomesOf($result));
    }

    private function weeklyStatement(string $reference, string $periodStart, ?float $tax, int $structureId = self::ROCK_HOLLOW, int $characterId = self::ILYRA): MoonTaxStatement
    {
        $start = $this->at($periodStart . ' 00:00:00');

        return (new MoonTaxStatement())
            ->setCorporationId(self::CORPORATION)
            ->setStructureId($structureId)
            ->setCharacterId($characterId)
            ->setPeriodStart($start)
            ->setPeriodEnd($start->modify('+7 days'))
            ->setReference($reference)
            ->setTax($tax);
    }

    private function donation(int $journalId, int $firstPartyId, float $amount, string $date): CorporationWalletDonation
    {
        return (new CorporationWalletDonation())
            ->setCorporationId(self::CORPORATION)
            ->setJournalId($journalId)
            ->setFirstPartyId($firstPartyId)
            ->setAmount($amount)
            ->setDate($this->at($date));
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    private function outcomesOf(array $result): array
    {
        return array_map(static fn (MoonTaxSettlementLine $line): array => [$line->paid, $line->remaining, $line->status->value], $result);
    }
}
