<?php

declare(strict_types=1);

namespace App\Service\Mining;

use App\Entity\MiningEntry;
use App\Entity\User;
use App\Repository\MiningEntryRepository;
use App\Service\MiningBestValueCalculator;
use App\Service\Pve\PveRevenueGridBuilder;

final readonly class MiningRevenueGridBuilder
{
    public const string SOLD = MiningEntry::USAGE_SOLD;
    public const string CORP_PROJECT = MiningEntry::USAGE_CORP_PROJECT;
    public const string UNCATEGORISED = MiningEntry::USAGE_UNKNOWN;

    private const array COLUMNS = [self::SOLD, self::CORP_PROJECT, self::UNCATEGORISED];

    public function __construct(
        private MiningEntryRepository $entryRepository,
        private MiningBestValueCalculator $bestValueCalculator,
    ) {
    }

    /**
     * @return array{
     *     columns: array<string, array{value: float|null, previous: float|null, change: float|null, quantity: int, daily: array<string, float>|null, contributors: list<array{id: int, name: string, value: float}>|null}>,
     *     net: array{value: float|null, previous: float|null, change: float|null, daily: array<string, float>, contributors: list<array{id: int, name: string, value: float}>},
     *     missingPriceTypeIds: list<int>
     * }
     */
    public function build(User $user, \DateTimeImmutable $from, \DateTimeImmutable $to, int $days, bool $corpProjectCountedInPve): array
    {
        $previousTo = $from->modify('-1 second');
        $previousFrom = $from->modify("-{$days} days");

        $currentRows = $this->entryRepository->getQuantitiesByUsageDayTypeAndCharacter($user, $from, $to, self::COLUMNS);
        $previousRows = $this->entryRepository->getQuantitiesByUsageDayTypeAndCharacter($user, $previousFrom, $previousTo, self::COLUMNS);
        $unitPrices = $this->unitPricesOf([...$currentRows, ...$previousRows], $user);

        $current = $this->valueColumns($currentRows, $unitPrices);
        $previous = $this->valueColumns($previousRows, $unitPrices);

        $columns = [];
        foreach (self::COLUMNS as $column) {
            $columns[$column] = [
                'value' => $current[$column]['value'],
                'previous' => $previous[$column]['value'],
                'change' => self::changeBetween($current[$column]['value'], $previous[$column]['value']),
                'quantity' => $current[$column]['quantity'],
                'daily' => $current[$column]['daily'],
                'contributors' => $current[$column]['contributors'],
            ];
        }

        $counted = $corpProjectCountedInPve ? [self::SOLD] : [self::SOLD, self::CORP_PROJECT];

        return [
            'columns' => $columns,
            'net' => $this->net($current, $previous, $counted),
            'missingPriceTypeIds' => $this->missingPriceTypeIds($current),
        ];
    }

    /**
     * @param list<array{usage: string, day: string, typeId: int, characterId: int, characterName: string, quantity: int}> $rows
     * @return array<int, float|null>
     */
    private function unitPricesOf(array $rows, User $user): array
    {
        $typeIds = [];
        foreach ($rows as $row) {
            if ($row['quantity'] !== 0) {
                $typeIds[$row['typeId']] = true;
            }
        }

        return $this->bestValueCalculator->getBestUnitPrices($user, array_keys($typeIds));
    }

    /**
     * @param list<array{usage: string, day: string, typeId: int, characterId: int, characterName: string, quantity: int}> $rows
     * @param array<int, float|null> $unitPrices
     * @return array<string, array{value: float|null, quantity: int, daily: array<string, float>|null, contributors: list<array{id: int, name: string, value: float}>|null, missingPriceTypeIds: list<int>}>
     */
    private function valueColumns(array $rows, array $unitPrices): array
    {
        $values = array_fill_keys(self::COLUMNS, 0.0);
        $quantities = array_fill_keys(self::COLUMNS, 0);
        $missing = array_fill_keys(self::COLUMNS, []);
        $daily = array_fill_keys(self::COLUMNS, []);
        $byCharacter = array_fill_keys(self::COLUMNS, []);
        $names = [];

        foreach ($rows as $row) {
            if ($row['quantity'] === 0) {
                continue;
            }
            $column = $row['usage'];
            $quantities[$column] += $row['quantity'];
            $unitPrice = $unitPrices[$row['typeId']];
            if ($unitPrice === null) {
                $missing[$column][$row['typeId']] = $row['typeId'];
                continue;
            }
            $amount = $unitPrice * $row['quantity'];
            $values[$column] += $amount;
            $daily[$column][$row['day']] = ($daily[$column][$row['day']] ?? 0.0) + $amount;
            $byCharacter[$column][$row['characterId']] = ($byCharacter[$column][$row['characterId']] ?? 0.0) + $amount;
            $names[$row['characterId']] = $row['characterName'];
        }

        $valued = [];
        foreach (self::COLUMNS as $column) {
            $isComplete = $missing[$column] === [];
            ksort($daily[$column]);
            $missingTypeIds = array_values($missing[$column]);
            sort($missingTypeIds);
            $valued[$column] = [
                'value' => $isComplete ? $values[$column] : null,
                'quantity' => $quantities[$column],
                'daily' => $isComplete ? $daily[$column] : null,
                'contributors' => $isComplete ? $this->contributorList($byCharacter[$column], $names) : null,
                'missingPriceTypeIds' => $missingTypeIds,
            ];
        }

        return $valued;
    }

    /**
     * @param array<int, float> $valuesByCharacter
     * @param array<int, string> $names
     * @return list<array{id: int, name: string, value: float}>
     */
    private function contributorList(array $valuesByCharacter, array $names): array
    {
        $list = [];
        foreach ($valuesByCharacter as $characterId => $value) {
            $list[] = ['id' => $characterId, 'name' => $names[$characterId], 'value' => $value];
        }

        return $list;
    }

    /**
     * @param array<string, array{value: float|null, quantity: int, daily: array<string, float>|null, contributors: list<array{id: int, name: string, value: float}>|null, missingPriceTypeIds: list<int>}> $current
     * @param array<string, array{value: float|null, quantity: int, daily: array<string, float>|null, contributors: list<array{id: int, name: string, value: float}>|null, missingPriceTypeIds: list<int>}> $previous
     * @param list<string> $counted
     * @return array{value: float|null, previous: float|null, change: float|null, daily: array<string, float>, contributors: list<array{id: int, name: string, value: float}>}
     */
    private function net(array $current, array $previous, array $counted): array
    {
        $value = self::sumKnown($current, $counted);
        $previousValue = self::sumKnown($previous, $counted);
        $isComparable = !self::hasUnknown($current, $counted) && !self::hasUnknown($previous, $counted);

        $daily = [];
        $contributors = [];
        $names = [];
        foreach ($counted as $column) {
            foreach ($current[$column]['daily'] ?? [] as $day => $amount) {
                $daily[$day] = ($daily[$day] ?? 0.0) + $amount;
            }
            foreach ($current[$column]['contributors'] ?? [] as $contributor) {
                $contributors[$contributor['id']] = ($contributors[$contributor['id']] ?? 0.0) + $contributor['value'];
                $names[$contributor['id']] = $contributor['name'];
            }
        }
        ksort($daily);

        return [
            'value' => $value,
            'previous' => $previousValue,
            'change' => $isComparable ? self::changeBetween($value, $previousValue) : null,
            'daily' => $daily,
            'contributors' => $this->contributorList($contributors, $names),
        ];
    }

    /**
     * @param array<string, array{value: float|null}> $columns
     * @param list<string> $counted
     */
    private static function sumKnown(array $columns, array $counted): ?float
    {
        $known = array_filter(array_map(static fn (string $column): ?float => $columns[$column]['value'], $counted), static fn (?float $value): bool => $value !== null);

        return $known === [] ? null : array_sum($known);
    }

    /**
     * @param array<string, array{value: float|null}> $columns
     * @param list<string> $counted
     */
    private static function hasUnknown(array $columns, array $counted): bool
    {
        foreach ($counted as $column) {
            if ($columns[$column]['value'] === null) {
                return true;
            }
        }

        return false;
    }

    private static function changeBetween(?float $current, ?float $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }

        return PveRevenueGridBuilder::changePercent($current, $previous);
    }

    /**
     * @param array<string, array{missingPriceTypeIds: list<int>}> $columns
     * @return list<int>
     */
    private function missingPriceTypeIds(array $columns): array
    {
        $missing = array_unique(array_merge(...array_map(static fn (array $column): array => $column['missingPriceTypeIds'], array_values($columns))));
        sort($missing);

        return $missing;
    }
}
