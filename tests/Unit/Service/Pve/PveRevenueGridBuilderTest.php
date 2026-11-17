<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Pve;

use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;
use App\Service\Pve\PveRevenueGridBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(PveRevenueGridBuilder::class)]
#[Group('issue-1065')]
final class PveRevenueGridBuilderTest extends TestCase
{
    private const int DAYS = 7;

    #[DataProvider('changeCases')]
    public function testChangePercentComparesWithThePreviousPeriodAndIsUnknownWithoutOne(float $current, float $previous, ?float $expected): void
    {
        $this->assertSame($expected, PveRevenueGridBuilder::changePercent($current, $previous));
    }

    /**
     * @return iterable<string, array{float, float, float|null}>
     */
    public static function changeCases(): iterable
    {
        yield 'growth' => [150.0, 100.0, 50.0];
        yield 'fall' => [25.0, 100.0, -75.0];
        yield 'no change' => [100.0, 100.0, 0.0];
        yield 'no previous revenue' => [100.0, 0.0, null];
        yield 'no revenue at all' => [0.0, 0.0, null];
        yield 'recovery from a loss' => [50.0, -100.0, 150.0];
    }

    #[DataProvider('descriptions')]
    public function testCharacterNameIsReadAfterTheFirstSeparatorOnly(string $description, ?string $expected): void
    {
        $this->assertSame($expected, PveRevenueGridBuilder::characterNameFromDescription($description));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function descriptions(): iterable
    {
        yield 'bounty' => ['Bounty - Main Pilot', 'Main Pilot'];
        yield 'name with a dash' => ['ESS - Pilot - Two', 'Pilot - Two'];
        yield 'no character' => ['Bounty', null];
        yield 'empty character' => ['Bounty - ', null];
        yield 'loot description' => ['12x Tritanium', null];
    }

    public function testEachColumnCarriesItsTotalItsChangeAndItsDailyAmounts(): void
    {
        $grid = $this->builder(
            currentByType: [
                PveIncomeType::Bounty->value => 300.0,
                PveIncomeType::Ess->value => 40.0,
                PveIncomeType::Mission->value => 10.0,
                PveIncomeType::LootSale->value => 20.0,
                PveIncomeType::LootContract->value => 5.0,
                PveIncomeType::Escalation->value => 100.0,
            ],
            previousByType: [PveIncomeType::Bounty->value => 200.0, PveIncomeType::Ess->value => 80.0],
            expenses: [60.0, 0.0],
            dailyByType: [
                '2026-11-16' => [PveIncomeType::Bounty->value => 100.0, PveIncomeType::LootSale->value => 20.0],
                '2026-11-17' => [PveIncomeType::Bounty->value => 200.0, PveIncomeType::LootContract->value => 5.0],
            ],
            dailyExpenses: ['2026-11-17' => ['date' => '2026-11-17', 'total' => 60.0]],
        )->build(new User(), $this->from(), $this->to(), self::DAYS, false);

        $this->assertSame(300.0, $grid['columns']['bounties']['value']);
        $this->assertSame(50.0, $grid['columns']['bounties']['change']);
        $this->assertSame(-50.0, $grid['columns']['ess']['change']);
        $this->assertNull($grid['columns']['missions']['change']);
        $this->assertSame(25.0, $grid['columns']['lootSales']['value']);
        $this->assertSame(['2026-11-16' => 100.0, '2026-11-17' => 200.0], $grid['columns']['bounties']['daily']);
        $this->assertSame(['2026-11-16' => 20.0, '2026-11-17' => 5.0], $grid['columns']['lootSales']['daily']);
        $this->assertSame(60.0, $grid['columns']['expenses']['value']);
        $this->assertNull($grid['columns']['expenses']['change']);
        $this->assertSame(['2026-11-17' => 60.0], $grid['columns']['expenses']['daily']);
    }

    public function testNetSubtractsTheExpensesAndHasItsOwnChangeAndDailyAmounts(): void
    {
        $grid = $this->builder(
            currentByType: [PveIncomeType::Bounty->value => 300.0, PveIncomeType::Escalation->value => 100.0],
            previousByType: [PveIncomeType::Bounty->value => 160.0],
            expenses: [100.0, 60.0],
            dailyByType: ['2026-11-17' => [PveIncomeType::Bounty->value => 300.0]],
            dailyExpenses: ['2026-11-17' => ['date' => '2026-11-17', 'total' => 100.0], '2026-11-16' => ['date' => '2026-11-16', 'total' => 20.0]],
        )->build(new User(), $this->from(), $this->to(), self::DAYS, false);

        $this->assertSame(300.0, $grid['net']['value']);
        $this->assertSame(100.0, $grid['net']['previous']);
        $this->assertSame(200.0, $grid['net']['change']);
        $this->assertSame(['2026-11-16' => -20.0, '2026-11-17' => 200.0], $grid['net']['daily']);
    }

    public function testCorpProjectIncomeIsCountedAsLootUnlessMiningAccountsForIt(): void
    {
        $byType = [PveIncomeType::LootSale->value => 10.0, PveIncomeType::CorpProject->value => 90.0];
        $dailyByType = ['2026-11-17' => [PveIncomeType::LootSale->value => 10.0, PveIncomeType::CorpProject->value => 90.0]];

        $counted = $this->builder($byType, [], [0.0, 0.0], $dailyByType)->build(new User(), $this->from(), $this->to(), self::DAYS, false);
        $excluded = $this->builder($byType, [], [0.0, 0.0], $dailyByType)->build(new User(), $this->from(), $this->to(), self::DAYS, true);

        $this->assertSame(100.0, $counted['columns']['lootSales']['value']);
        $this->assertSame(['2026-11-17' => 100.0], $counted['columns']['lootSales']['daily']);
        $this->assertSame(10.0, $excluded['columns']['lootSales']['value']);
        $this->assertSame(['2026-11-17' => 10.0], $excluded['columns']['lootSales']['daily']);
    }

    public function testContributorsAreTheCharactersNamedInTheBountyEssAndMissionDescriptions(): void
    {
        $grid = $this->builder(
            currentByType: [],
            previousByType: [],
            expenses: [0.0, 0.0],
            contributorRows: [
                ['type' => PveIncomeType::Bounty->value, 'description' => 'Bounty - Main', 'total' => 70.0],
                ['type' => PveIncomeType::Bounty->value, 'description' => 'Bounty - Alt', 'total' => 30.0],
                ['type' => PveIncomeType::Ess->value, 'description' => 'ESS - Main', 'total' => 5.0],
                ['type' => PveIncomeType::Mission->value, 'description' => 'Mission Reward - Alt', 'total' => 4.0],
                ['type' => PveIncomeType::Mission->value, 'description' => 'Mission Bonus - Alt', 'total' => 1.0],
            ],
        )->build(new User(), $this->from(), $this->to(), self::DAYS, false);

        $this->assertSame([['name' => 'Main', 'value' => 70.0], ['name' => 'Alt', 'value' => 30.0]], $grid['columns']['bounties']['contributors']);
        $this->assertSame([['name' => 'Main', 'value' => 5.0]], $grid['columns']['ess']['contributors']);
        $this->assertSame([['name' => 'Alt', 'value' => 5.0]], $grid['columns']['missions']['contributors']);
    }

    public function testContributorsStayUnknownWhereNoCharacterIsRecorded(): void
    {
        $grid = $this->builder([], [], [0.0, 0.0])->build(new User(), $this->from(), $this->to(), self::DAYS, false);

        $this->assertNull($grid['columns']['lootSales']['contributors']);
        $this->assertNull($grid['columns']['escalations']['contributors']);
        $this->assertNull($grid['columns']['expenses']['contributors']);
    }

    public function testAColumnWithAnUnattributableRowHasUnknownContributorsInsteadOfPartialOnes(): void
    {
        $grid = $this->builder(
            [],
            [],
            [0.0, 0.0],
            contributorRows: [
                ['type' => PveIncomeType::Bounty->value, 'description' => 'Bounty - Main', 'total' => 70.0],
                ['type' => PveIncomeType::Bounty->value, 'description' => 'Bounty', 'total' => 30.0],
                ['type' => PveIncomeType::Ess->value, 'description' => 'ESS - Main', 'total' => 5.0],
            ],
        )->build(new User(), $this->from(), $this->to(), self::DAYS, false);

        $this->assertNull($grid['columns']['bounties']['contributors']);
        $this->assertSame([['name' => 'Main', 'value' => 5.0]], $grid['columns']['ess']['contributors']);
    }

    public function testThePreviousPeriodEndsJustBeforeTheCurrentOneAndHasTheSameLength(): void
    {
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getDailyTotalsPerIncomeType')->willReturn([]);
        $incomeRepository->method('getTotalsByTypeAndDescription')->willReturn([]);
        $periods = [];
        $incomeRepository->method('getTotalsByType')->willReturnCallback(function (User $user, \DateTimeImmutable $from, \DateTimeImmutable $to) use (&$periods): array {
            $periods[] = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];

            return [];
        });
        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getTotalByUserAndDateRange')->willReturn(0.0);
        $expenseRepository->method('getDailyTotals')->willReturn([]);

        (new PveRevenueGridBuilder($incomeRepository, $expenseRepository))->build(new User(), $this->from(), $this->to(), self::DAYS, false);

        $this->assertSame([
            ['2026-11-10 12:00:00', '2026-11-17 12:00:00'],
            ['2026-11-03 12:00:00', '2026-11-10 11:59:59'],
        ], $periods);
    }

    /**
     * @param array<string, float> $currentByType
     * @param array<string, float> $previousByType
     * @param array{0: float, 1: float} $expenses current then previous period
     * @param array<string, array<string, float>> $dailyByType
     * @param array<string, array{date: string, total: float}> $dailyExpenses
     * @param list<array{type: string, description: string, total: float}> $contributorRows
     */
    private function builder(
        array $currentByType,
        array $previousByType,
        array $expenses,
        array $dailyByType = [],
        array $dailyExpenses = [],
        array $contributorRows = [],
    ): PveRevenueGridBuilder {
        $incomeRepository = $this->createStub(PveIncomeRepository::class);
        $incomeRepository->method('getTotalsByType')->willReturnOnConsecutiveCalls($currentByType, $previousByType);
        $incomeRepository->method('getDailyTotalsPerIncomeType')->willReturn($dailyByType);
        $incomeRepository->method('getTotalsByTypeAndDescription')->willReturn($contributorRows);
        $expenseRepository = $this->createStub(PveExpenseRepository::class);
        $expenseRepository->method('getTotalByUserAndDateRange')->willReturnOnConsecutiveCalls($expenses[0], $expenses[1]);
        $expenseRepository->method('getDailyTotals')->willReturn($dailyExpenses);

        return new PveRevenueGridBuilder($incomeRepository, $expenseRepository);
    }

    private function from(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-10 12:00:00');
    }

    private function to(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17 12:00:00');
    }
}
