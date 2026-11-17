<?php

declare(strict_types=1);

namespace App\Service\Pve;

use App\Entity\User;
use App\Enum\PveIncomeType;
use App\Repository\PveExpenseRepository;
use App\Repository\PveIncomeRepository;

final readonly class PveRevenueGridBuilder
{
    public const string BOUNTIES = 'bounties';
    public const string ESS = 'ess';
    public const string MISSIONS = 'missions';
    public const string LOOT_SALES = 'lootSales';
    public const string ESCALATIONS = 'escalations';
    public const string EXPENSES = 'expenses';

    private const int PERCENT = 100;
    private const string CHARACTER_SEPARATOR = ' - ';

    public function __construct(
        private PveIncomeRepository $incomeRepository,
        private PveExpenseRepository $expenseRepository,
    ) {
    }

    /**
     * @return array{
     *     columns: array<string, array{value: float, previous: float, change: float|null, daily: array<string, float>, contributors: list<array{name: string, value: float}>|null}>,
     *     net: array{value: float, previous: float, change: float|null, daily: array<string, float>}
     * }
     */
    public function build(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, int $days, bool $excludeCorpProject): array
    {
        $previousTo = $from->modify('-1 second');
        $previousFrom = $from->modify("-{$days} days");

        $current = $this->totals($user, $from, $to, $excludeCorpProject);
        $previous = $this->totals($user, $previousFrom, $previousTo, $excludeCorpProject);
        $daily = $this->dailyAmounts($user, $from, $to, $excludeCorpProject);
        $contributors = $this->contributors($user, $from, $to);

        $columns = [];
        foreach (array_keys($current) as $column) {
            $columns[$column] = [
                'value' => $current[$column],
                'previous' => $previous[$column],
                'change' => self::changePercent($current[$column], $previous[$column]),
                'daily' => $daily[$column],
                'contributors' => $contributors[$column] ?? null,
            ];
        }

        $net = self::net($current);
        $previousNet = self::net($previous);

        return [
            'columns' => $columns,
            'net' => [
                'value' => $net,
                'previous' => $previousNet,
                'change' => self::changePercent($net, $previousNet),
                'daily' => self::netDaily($daily),
            ],
        ];
    }

    public static function changePercent(float $current, float $previous): ?float
    {
        if ($previous === 0.0) {
            return null;
        }

        return ($current - $previous) / abs($previous) * self::PERCENT;
    }

    public static function characterNameFromDescription(string $description): ?string
    {
        $parts = explode(self::CHARACTER_SEPARATOR, $description, 2);
        $name = isset($parts[1]) ? trim($parts[1]) : '';

        return $name === '' ? null : $name;
    }

    /**
     * @param array<string, float> $totals
     */
    private static function net(array $totals): float
    {
        return $totals[self::BOUNTIES] + $totals[self::ESS] + $totals[self::MISSIONS] + $totals[self::LOOT_SALES] + $totals[self::ESCALATIONS] - $totals[self::EXPENSES];
    }

    /**
     * @param array<string, array<string, float>> $daily
     * @return array<string, float>
     */
    private static function netDaily(array $daily): array
    {
        $net = [];
        foreach ($daily as $column => $amounts) {
            foreach ($amounts as $day => $amount) {
                $net[$day] = ($net[$day] ?? 0.0) + ($column === self::EXPENSES ? -$amount : $amount);
            }
        }
        ksort($net);

        return $net;
    }

    /**
     * @return array<string, float>
     */
    private function totals(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $excludeCorpProject): array
    {
        $byType = $this->incomeRepository->getTotalsByType($user, $from, $to);
        $lootSales = ($byType[PveIncomeType::LootSale->value] ?? 0.0) + ($byType[PveIncomeType::LootContract->value] ?? 0.0);
        if (!$excludeCorpProject) {
            $lootSales += $byType[PveIncomeType::CorpProject->value] ?? 0.0;
        }

        return [
            self::BOUNTIES => $byType[PveIncomeType::Bounty->value] ?? 0.0,
            self::ESS => $byType[PveIncomeType::Ess->value] ?? 0.0,
            self::MISSIONS => $byType[PveIncomeType::Mission->value] ?? 0.0,
            self::LOOT_SALES => $lootSales,
            self::ESCALATIONS => $byType[PveIncomeType::Escalation->value] ?? 0.0,
            self::EXPENSES => $this->expenseRepository->getTotalByUserAndDateRange($user, $from, $to),
        ];
    }

    /**
     * @return array<string, array<string, float>>
     */
    private function dailyAmounts(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $excludeCorpProject): array
    {
        $daily = [
            self::BOUNTIES => [],
            self::ESS => [],
            self::MISSIONS => [],
            self::LOOT_SALES => [],
            self::ESCALATIONS => [],
            self::EXPENSES => [],
        ];
        $lootSaleTypes = [PveIncomeType::LootSale->value, PveIncomeType::LootContract->value];
        if (!$excludeCorpProject) {
            $lootSaleTypes[] = PveIncomeType::CorpProject->value;
        }
        $columnOfType = [
            PveIncomeType::Bounty->value => self::BOUNTIES,
            PveIncomeType::Ess->value => self::ESS,
            PveIncomeType::Mission->value => self::MISSIONS,
            PveIncomeType::Escalation->value => self::ESCALATIONS,
        ];
        foreach ($lootSaleTypes as $type) {
            $columnOfType[$type] = self::LOOT_SALES;
        }

        foreach ($this->incomeRepository->getDailyTotalsPerIncomeType($user, $from, $to) as $day => $amountsByType) {
            foreach ($amountsByType as $type => $amount) {
                if (isset($columnOfType[$type])) {
                    $daily[$columnOfType[$type]][$day] = ($daily[$columnOfType[$type]][$day] ?? 0.0) + $amount;
                }
            }
        }

        foreach ($this->expenseRepository->getDailyTotals($user, $from, $to) as $day => $row) {
            $daily[self::EXPENSES][$day] = $row['total'];
        }

        foreach ($daily as $column => $amounts) {
            ksort($amounts);
            $daily[$column] = $amounts;
        }

        return $daily;
    }

    /**
     * @return array<string, list<array{name: string, value: float}>|null>
     */
    private function contributors(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $columnOfType = [
            PveIncomeType::Bounty->value => self::BOUNTIES,
            PveIncomeType::Ess->value => self::ESS,
            PveIncomeType::Mission->value => self::MISSIONS,
        ];
        $rows = $this->incomeRepository->getTotalsByTypeAndDescription($user, $from, $to, [PveIncomeType::Bounty, PveIncomeType::Ess, PveIncomeType::Mission]);

        $perColumn = array_fill_keys($columnOfType, []);
        $unattributable = array_fill_keys($columnOfType, false);
        foreach ($rows as $row) {
            $column = $columnOfType[$row['type']];
            $name = self::characterNameFromDescription($row['description']);
            if ($name === null) {
                $unattributable[$column] = true;
                continue;
            }
            $perColumn[$column][$name] = ($perColumn[$column][$name] ?? 0.0) + $row['total'];
        }

        $contributors = [];
        foreach ($perColumn as $column => $valuesByName) {
            if ($unattributable[$column]) {
                $contributors[$column] = null;
                continue;
            }
            $list = [];
            foreach ($valuesByName as $name => $value) {
                $list[] = ['name' => (string) $name, 'value' => $value];
            }
            $contributors[$column] = $list;
        }

        return $contributors;
    }
}
