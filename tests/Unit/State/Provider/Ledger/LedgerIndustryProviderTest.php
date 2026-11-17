<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\Ledger;

use ApiPlatform\Metadata\Get;
use App\Entity\User;
use App\Service\Industry\Revenue\IndustryRevenueProduct;
use App\Service\Industry\Revenue\IndustryRevenueReader;
use App\Service\Industry\Revenue\IndustryRevenueReport;
use App\Service\Industry\Revenue\IndustryRevenueTotals;
use App\State\Provider\Ledger\LedgerIndustryProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

#[CoversClass(LedgerIndustryProvider::class)]
#[Group('issue-1067')]
final class LedgerIndustryProviderTest extends TestCase
{
    private const float VALUE_DELTA = 1e-6;

    public function testProfitChangeComparesThePeriodWithThePreviousPeriodOfTheSameLength(): void
    {
        $resource = $this->providerReading(
            $this->report(150_000_000.0, 3, 0),
            $this->report(100_000_000.0, 2, 0),
        )->provide(new Get());

        self::assertEqualsWithDelta(50.0, $resource->totals['change'], self::VALUE_DELTA);
    }

    public function testProfitChangeUsesTheAbsolutePreviousProfitWhenThePreviousPeriodLostMoney(): void
    {
        $resource = $this->providerReading(
            $this->report(10_000_000.0, 1, 0),
            $this->report(-40_000_000.0, 1, 0),
        )->provide(new Get());

        self::assertEqualsWithDelta(125.0, $resource->totals['change'], self::VALUE_DELTA);
    }

    public function testProfitChangeIsUnknownWhenThePreviousPeriodHasNoCompletedProject(): void
    {
        $resource = $this->providerReading($this->report(150_000_000.0, 3, 0), $this->report(0.0, 0, 0))->provide(new Get());

        self::assertNull($resource->totals['change']);
    }

    public function testProfitChangeIsUnknownWhenEveryProjectOfThePreviousPeriodHasAnUnknownProfit(): void
    {
        $resource = $this->providerReading($this->report(150_000_000.0, 3, 0), $this->report(0.0, 2, 2))->provide(new Get());

        self::assertNull($resource->totals['change']);
    }

    public function testProfitChangeIsUnknownWhenEveryProjectOfThePeriodHasAnUnknownProfit(): void
    {
        $resource = $this->providerReading($this->report(0.0, 2, 2), $this->report(100_000_000.0, 1, 0))->provide(new Get());

        self::assertNull($resource->totals['change']);
    }

    public function testProfitChangeIsUnknownWhenThePreviousProfitIsExactlyZero(): void
    {
        $resource = $this->providerReading($this->report(10_000_000.0, 1, 0), $this->report(0.0, 1, 0))->provide(new Get());

        self::assertNull($resource->totals['change']);
    }

    public function testPreviousPeriodIsTheSameNumberOfDaysEndingWhereThePeriodStarts(): void
    {
        $user = new User();
        $reader = $this->createMock(IndustryRevenueReader::class);
        $periods = [];
        $reader->expects($this->exactly(2))
            ->method('reportFor')
            ->willReturnCallback(function (User $reportUser, \DateTimeImmutable $from, \DateTimeImmutable $to) use ($user, &$periods): IndustryRevenueReport {
                self::assertSame($user, $reportUser);
                $periods[] = [$from, $to];

                return $this->report(0.0, 0, 0);
            });

        $this->buildProvider($user, $reader, ['days' => '7'])->provide(new Get());

        [[$currentFrom, $currentTo], [$previousFrom, $previousTo]] = $periods;
        self::assertSame(7, (int) $currentFrom->diff($currentTo)->days);
        self::assertSame(7, (int) $previousFrom->diff($previousTo)->days);
        self::assertSame($currentFrom->format('c'), $previousTo->format('c'));
    }

    public function testResourceCarriesTheDailyProfitAndTheCapitalAndMarginOfEachProduct(): void
    {
        $current = new IndustryRevenueReport(
            new IndustryRevenueTotals(30_000_000.0, 1, 0, 100_000_000.0, null, null),
            [],
            [new IndustryRevenueProduct(22456, 'Sabre', 1, 0, 30_000_000.0, 100_000_000.0, 30.0)],
            ['2026-11-03' => 30_000_000.0],
        );

        $resource = $this->providerReading($current, $this->report(0.0, 0, 0))->provide(new Get());

        self::assertSame(['2026-11-03' => 30_000_000.0], $resource->daily);
        self::assertSame(
            [['typeId' => 22456, 'typeName' => 'Sabre', 'projectCount' => 1, 'unknownProjectCount' => 0, 'profit' => 30_000_000.0, 'capital' => 100_000_000.0, 'marginPercent' => 30.0]],
            $resource->products,
        );
    }

    private function report(float $profit, int $projectCount, int $unknownProjectCount): IndustryRevenueReport
    {
        return new IndustryRevenueReport(
            new IndustryRevenueTotals($profit, $projectCount, $unknownProjectCount, 0.0, null, null),
            [],
            [],
        );
    }

    private function providerReading(IndustryRevenueReport $current, IndustryRevenueReport $previous): LedgerIndustryProvider
    {
        $reader = $this->createStub(IndustryRevenueReader::class);
        $reader->method('reportFor')->willReturnOnConsecutiveCalls($current, $previous);

        return $this->buildProvider(new User(), $reader, []);
    }

    /**
     * @param array<string, string> $query
     */
    private function buildProvider(User $user, IndustryRevenueReader $reader, array $query): LedgerIndustryProvider
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $requestStack = new RequestStack();
        $requestStack->push(new Request($query));

        return new LedgerIndustryProvider($security, $requestStack, $reader);
    }
}
