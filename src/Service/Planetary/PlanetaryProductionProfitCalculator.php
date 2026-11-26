<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Service\StructureMarketService;

final readonly class PlanetaryProductionProfitCalculator
{
    public const int TAX_DAYS = 30;
    private const string EXTRACTED_TIER = 'P0';
    private const int PERCENT = 100;

    public function __construct(
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetaryProductionCalculator $productionCalculator,
        private PlanetaryPaidPriceCalculator $paidPriceCalculator,
        private StructureMarketService $structureMarketService,
        private CachedWalletJournalEntryRepository $journalRepository,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    /**
     * @return array{
     *     hub: array{structureId: int, structureName: string, hasData: bool},
     *     lines: list<array{typeId: int, typeName: string|null, tier: string, dailyQuantity: float, soldQuantity: float, internal: bool, hubSellPrice: float|null, salesIsk: float|null, inputsIsk: float|null, profitPerDay: float|null, marginPercent: float|null, valuesExtractedAtHubBuy: bool, jitaIsk: float|null}>,
     *     salesIsk: float,
     *     inputsIsk: float,
     *     profitPerDay: float,
     *     unknownLineCount: int,
     *     taxDays: int,
     *     taxesPerDay: float|null,
     *     netProfitPerDay: float,
     *     unsyncedWallets: list<array{characterId: int, characterName: string, status: string}>
     * }
     */
    public function calculate(User $user, \DateTimeImmutable $now): array
    {
        $items = $this->productionItems($this->colonyRepository->findByUser($user));
        $hubId = $user->getEffectiveMarketStructureId() ?? $this->defaultMarketStructureId;
        $paidReport = $this->paidPriceCalculator->calculate($user, $now);

        $productTypeIds = [];
        $extractedTypeIds = [];
        foreach ($items as $typeId => $item) {
            if ($item['tier'] === self::EXTRACTED_TIER) {
                $extractedTypeIds[] = $typeId;
            } elseif ($item['dailyQuantity'] > 0.0) {
                $productTypeIds[] = $typeId;
            }
        }

        $costing = new PlanetaryProductionCosting(
            $items,
            array_column($paidReport['prices'], 'paidPrice', 'typeId'),
            $this->structureMarketService->getHighestBuyPrices($hubId, $extractedTypeIds),
        );
        $hubSellPrices = $this->structureMarketService->getLowestSellPrices($hubId, $productTypeIds);

        $lines = [];
        foreach ($productTypeIds as $typeId) {
            $lines[] = $this->lineOf($items[$typeId], $typeId, $hubSellPrices[$typeId] ?? null, $costing);
        }
        usort($lines, static fn (array $a, array $b): int => [$a['internal'], -($a['salesIsk'] ?? -INF), $a['typeId']] <=> [$b['internal'], -($b['salesIsk'] ?? -INF), $b['typeId']]);

        $known = array_filter($lines, static fn (array $line): bool => $line['profitPerDay'] !== null);
        $profitPerDay = round(array_sum(array_column($known, 'profitPerDay')), 2);
        $taxesPerDay = $this->taxesPerDay($user, $now);

        return [
            'hub' => [
                'structureId' => $hubId,
                'structureName' => $this->hubName($user, $hubId),
                'hasData' => $this->structureMarketService->hasCachedData($hubId),
            ],
            'lines' => $lines,
            'salesIsk' => round(array_sum(array_column($known, 'salesIsk')), 2),
            'inputsIsk' => round(array_sum(array_column($known, 'inputsIsk')), 2),
            'profitPerDay' => $profitPerDay,
            'unknownLineCount' => count(array_filter($lines, static fn (array $line): bool => !$line['internal'] && $line['profitPerDay'] === null)),
            'taxDays' => self::TAX_DAYS,
            'taxesPerDay' => $taxesPerDay,
            'netProfitPerDay' => $taxesPerDay === null ? $profitPerDay : round($profitPerDay - $taxesPerDay, 2),
            'unsyncedWallets' => $paidReport['unsyncedWallets'],
        ];
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return array<int, array{tier: string, typeName: string|null, dailyQuantity: float, sellable: float, unitPrice: float|null, jitaIsk: float, inputs: array<int, float>}>
     */
    private function productionItems(array $colonies): array
    {
        $items = [];
        foreach ($this->productionCalculator->calculateProduction($colonies)['tiers'] as $tier) {
            foreach ($tier['items'] as $item) {
                $items[$item['typeId']] = [
                    'tier' => $tier['tier'],
                    'typeName' => $item['typeName'],
                    'dailyQuantity' => (float) $item['dailyQuantity'],
                    'sellable' => (float) $item['dailySellableQuantity'],
                    'unitPrice' => $item['unitPrice'],
                    'jitaIsk' => (float) $item['dailyIskValue'],
                    'inputs' => array_column($item['inputs'], 'dailyConsumed', 'typeId'),
                ];
            }
        }

        return $items;
    }

    /**
     * @param array{tier: string, typeName: string|null, dailyQuantity: float, sellable: float, unitPrice: float|null, jitaIsk: float, inputs: array<int, float>} $item
     * @return array{typeId: int, typeName: string|null, tier: string, dailyQuantity: float, soldQuantity: float, internal: bool, hubSellPrice: float|null, salesIsk: float|null, inputsIsk: float|null, profitPerDay: float|null, marginPercent: float|null, valuesExtractedAtHubBuy: bool, jitaIsk: float|null}
     */
    private function lineOf(array $item, int $typeId, ?float $hubSellPrice, PlanetaryProductionCosting $costing): array
    {
        $sold = $item['sellable'];
        $internal = $sold <= 0.0;
        $unitCost = $internal ? null : $costing->unitCostOf($typeId);

        $salesIsk = $internal ? 0.0 : ($hubSellPrice === null ? null : round($sold * $hubSellPrice, 2));
        $inputsIsk = $unitCost === null ? null : round($sold * $unitCost['cost'], 2);
        $profit = $internal || $salesIsk === null || $inputsIsk === null ? null : round($salesIsk - $inputsIsk, 2);

        return [
            'typeId' => $typeId,
            'typeName' => $item['typeName'],
            'tier' => $item['tier'],
            'dailyQuantity' => $item['dailyQuantity'],
            'soldQuantity' => $sold,
            'internal' => $internal,
            'hubSellPrice' => $hubSellPrice,
            'salesIsk' => $salesIsk,
            'inputsIsk' => $inputsIsk,
            'profitPerDay' => $profit,
            'marginPercent' => $profit !== null && $salesIsk > 0.0 ? round($profit / $salesIsk * self::PERCENT, 2) : null,
            'valuesExtractedAtHubBuy' => $unitCost !== null && $unitCost['extracted'],
            'jitaIsk' => $item['unitPrice'] === null ? null : $item['jitaIsk'],
        ];
    }

    private function taxesPerDay(User $user, \DateTimeImmutable $now): ?float
    {
        $since = $now->modify(sprintf('-%d days', self::TAX_DAYS));
        $rows = $this->journalRepository->planetaryTaxesByColonyAndDay(array_values($user->getCharacters()->toArray()), $since);
        if ($rows === []) {
            return null;
        }

        $total = 0.0;
        foreach ($rows as $row) {
            $total += $row->importTax + $row->exportTax;
        }

        return round($total / self::TAX_DAYS, 2);
    }

    private function hubName(User $user, int $hubId): string
    {
        if ($user->getEffectiveMarketStructureId() === null) {
            return $this->defaultMarketStructureName;
        }

        return $user->getEffectiveMarketStructureName() ?? 'Structure '.$hubId;
    }
}
