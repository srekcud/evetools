<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mining;

use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Service\Mining\MiningRevenueGridBuilder;
use App\Service\MiningBestValueCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiningRevenueGridBuilder::class)]
#[Group('issue-1066')]
final class MiningRevenueGridBuilderTest extends TestCase
{
    private const int DAYS = 7;
    private const int VELDSPAR = 1230;
    private const int SCORDITE = 1228;
    private const int MAIN = 11;
    private const int ALT = 22;

    public function testEachUsageIsValuedAtTheUnitPriceWithItsChangeItsDailyAmountsAndItsContributors(): void
    {
        $grid = $this->build(
            current: [
                $this->row('sold', '2026-11-09', self::VELDSPAR, self::MAIN, 'Main', 100),
                $this->row('sold', '2026-11-10', self::VELDSPAR, self::ALT, 'Alt', 50),
                $this->row('corp_project', '2026-11-10', self::SCORDITE, self::MAIN, 'Main', 10),
            ],
            previous: [$this->row('sold', '2026-11-02', self::VELDSPAR, self::MAIN, 'Main', 75)],
            unitPrices: [self::VELDSPAR => 10.0, self::SCORDITE => 20.0],
        );

        $sold = $grid['columns']['sold'];
        $this->assertSame(1500.0, $sold['value']);
        $this->assertSame(750.0, $sold['previous']);
        $this->assertSame(100.0, $sold['change']);
        $this->assertSame(150, $sold['quantity']);
        $this->assertSame(['2026-11-09' => 1000.0, '2026-11-10' => 500.0], $sold['daily']);
        $this->assertSame([['id' => self::MAIN, 'name' => 'Main', 'value' => 1000.0], ['id' => self::ALT, 'name' => 'Alt', 'value' => 500.0]], $sold['contributors']);
        $this->assertSame(200.0, $grid['columns']['corp_project']['value']);
        $this->assertNull($grid['columns']['corp_project']['change']);
        $this->assertSame(0.0, $grid['columns']['unknown']['value']);
        $this->assertSame(0, $grid['columns']['unknown']['quantity']);
        $this->assertSame([], $grid['columns']['unknown']['contributors']);
    }

    public function testAColumnWithAnOreWithoutPriceIsUnknownAndNeverZero(): void
    {
        $grid = $this->build(
            current: [
                $this->row('sold', '2026-11-09', self::VELDSPAR, self::MAIN, 'Main', 100),
                $this->row('sold', '2026-11-09', self::SCORDITE, self::MAIN, 'Main', 40),
                $this->row('corp_project', '2026-11-09', self::VELDSPAR, self::MAIN, 'Main', 10),
            ],
            previous: [],
            unitPrices: [self::VELDSPAR => 10.0, self::SCORDITE => null],
        );

        $sold = $grid['columns']['sold'];
        $this->assertNull($sold['value']);
        $this->assertNull($sold['daily']);
        $this->assertNull($sold['contributors']);
        $this->assertNull($sold['change']);
        $this->assertSame(140, $sold['quantity']);
        $this->assertSame(100.0, $grid['columns']['corp_project']['value']);
        $this->assertSame([self::SCORDITE], $grid['missingPriceTypeIds']);
    }

    public function testTheNetAddsTheSoldAndCorpProjectColumnsAndLeavesTheUncategorisedOreOut(): void
    {
        $grid = $this->build(
            current: [
                $this->row('sold', '2026-11-09', self::VELDSPAR, self::MAIN, 'Main', 100),
                $this->row('corp_project', '2026-11-09', self::VELDSPAR, self::ALT, 'Alt', 20),
                $this->row('unknown', '2026-11-09', self::VELDSPAR, self::MAIN, 'Main', 1000),
            ],
            previous: [
                $this->row('sold', '2026-11-02', self::VELDSPAR, self::MAIN, 'Main', 60),
                $this->row('corp_project', '2026-11-02', self::VELDSPAR, self::MAIN, 'Main', 20),
            ],
            unitPrices: [self::VELDSPAR => 10.0],
        );

        $net = $grid['net'];
        $this->assertSame(1200.0, $net['value']);
        $this->assertSame(800.0, $net['previous']);
        $this->assertSame(50.0, $net['change']);
        $this->assertSame(['2026-11-09' => 1200.0], $net['daily']);
        $this->assertSame([['id' => self::MAIN, 'name' => 'Main', 'value' => 1000.0], ['id' => self::ALT, 'name' => 'Alt', 'value' => 200.0]], $net['contributors']);
    }

    public function testTheNetLeavesTheCorpProjectColumnOutWhenPveCountsIt(): void
    {
        $grid = $this->build(
            current: [
                $this->row('sold', '2026-11-09', self::VELDSPAR, self::MAIN, 'Main', 100),
                $this->row('corp_project', '2026-11-09', self::VELDSPAR, self::MAIN, 'Main', 20),
            ],
            previous: [],
            unitPrices: [self::VELDSPAR => 10.0],
            corpProjectCountedInPve: true,
        );

        $this->assertSame(1000.0, $grid['net']['value']);
        $this->assertSame(200.0, $grid['columns']['corp_project']['value']);
    }

    public function testTheNetKeepsTheKnownColumnsButHasNoChangeWhileACountedColumnIsUnknown(): void
    {
        $grid = $this->build(
            current: [
                $this->row('sold', '2026-11-09', self::VELDSPAR, self::MAIN, 'Main', 100),
                $this->row('corp_project', '2026-11-09', self::SCORDITE, self::MAIN, 'Main', 20),
            ],
            previous: [$this->row('sold', '2026-11-02', self::VELDSPAR, self::MAIN, 'Main', 50)],
            unitPrices: [self::VELDSPAR => 10.0, self::SCORDITE => null],
        );

        $this->assertSame(1000.0, $grid['net']['value']);
        $this->assertNull($grid['net']['change']);
    }

    public function testTheNetIsUnknownWhenEveryCountedColumnIsUnknown(): void
    {
        $grid = $this->build(
            current: [
                $this->row('sold', '2026-11-09', self::SCORDITE, self::MAIN, 'Main', 100),
                $this->row('corp_project', '2026-11-09', self::SCORDITE, self::MAIN, 'Main', 20),
            ],
            previous: [],
            unitPrices: [self::SCORDITE => null],
        );

        $this->assertNull($grid['net']['value']);
    }

    public function testAZeroQuantityRowNeverNeedsAPrice(): void
    {
        $grid = $this->build(
            current: [$this->row('sold', '2026-11-09', self::SCORDITE, self::MAIN, 'Main', 0)],
            previous: [],
            unitPrices: [self::SCORDITE => null],
        );

        $this->assertSame(0.0, $grid['columns']['sold']['value']);
        $this->assertSame([], $grid['missingPriceTypeIds']);
    }

    public function testThePreviousPeriodEndsOneSecondBeforeTheCurrentOne(): void
    {
        $repository = $this->createMock(MiningEntryRepository::class);
        $to = new \DateTimeImmutable('2026-11-10 12:00:00');
        $from = $to->modify('-7 days');
        $repository->expects($this->exactly(2))
            ->method('getQuantitiesByUsageDayTypeAndCharacter')
            ->willReturnCallback(function (User $user, \DateTimeImmutable $periodFrom, \DateTimeImmutable $periodTo, array $usages): array {
                static $call = 0;
                ++$call;
                if ($call === 1) {
                    $this->assertSame('2026-11-03 12:00:00', $periodFrom->format('Y-m-d H:i:s'));
                    $this->assertSame('2026-11-10 12:00:00', $periodTo->format('Y-m-d H:i:s'));
                } else {
                    $this->assertSame('2026-10-27 12:00:00', $periodFrom->format('Y-m-d H:i:s'));
                    $this->assertSame('2026-11-03 11:59:59', $periodTo->format('Y-m-d H:i:s'));
                }
                $this->assertSame(['sold', 'corp_project', 'unknown'], $usages);

                return [];
            });

        (new MiningRevenueGridBuilder($repository, $this->createStub(MiningBestValueCalculator::class)))->build(new User(), $from, $to, self::DAYS, false);
    }

    /**
     * @return array{usage: string, day: string, typeId: int, characterId: int, characterName: string, quantity: int}
     */
    private function row(string $usage, string $day, int $typeId, int $characterId, string $characterName, int $quantity): array
    {
        return ['usage' => $usage, 'day' => $day, 'typeId' => $typeId, 'characterId' => $characterId, 'characterName' => $characterName, 'quantity' => $quantity];
    }

    /**
     * @param list<array{usage: string, day: string, typeId: int, characterId: int, characterName: string, quantity: int}> $current
     * @param list<array{usage: string, day: string, typeId: int, characterId: int, characterName: string, quantity: int}> $previous
     * @param array<int, float|null> $unitPrices
     * @return array<string, mixed>
     */
    private function build(array $current, array $previous, array $unitPrices, bool $corpProjectCountedInPve = false): array
    {
        $repository = $this->createStub(MiningEntryRepository::class);
        $repository->method('getQuantitiesByUsageDayTypeAndCharacter')->willReturnOnConsecutiveCalls($current, $previous);
        $calculator = $this->createStub(MiningBestValueCalculator::class);
        $calculator->method('getBestUnitPrices')->willReturn($unitPrices);
        $to = new \DateTimeImmutable('2026-11-10 12:00:00');

        return (new MiningRevenueGridBuilder($repository, $calculator))->build(new User(), $to->modify('-7 days'), $to, self::DAYS, $corpProjectCountedInPve);
    }
}
