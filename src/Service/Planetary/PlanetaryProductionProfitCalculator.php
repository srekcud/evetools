<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Repository\CachedWalletJournalEntryRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\PlanetaryPlanTemplateRepository;
use App\Service\JitaMarketService;
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
        private JitaMarketService $jitaMarketService,
        private CachedWalletJournalEntryRepository $journalRepository,
        private PlanetaryPlanTemplateRepository $templateRepository,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    /**
     * @return array{
     *     hub: array{structureId: int, structureName: string, hasData: bool},
     *     lines: list<array{typeId: int, typeName: string|null, tier: string, dailyQuantity: float, soldQuantity: float, internal: bool, hubSellPrice: float|null, salePrice: float|null, salesIsk: float|null, inputsIsk: float|null, profitPerDay: float|null, marginPercent: float|null, valuesExtractedAtHubBuy: bool, jitaIsk: float|null, ifSold: array{salesIsk: float|null, inputsIsk: float|null, profitPerDay: float|null, marginPercent: float|null}|null}>,
     *     salesIsk: float,
     *     inputsIsk: float,
     *     sources: array{purchase: string, sale: string, followedTemplateName: string|null},
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
        $followed = $this->templateRepository->findFollowedByUser($user)[0] ?? null;
        $followedSettings = $followed?->getSettings();
        $purchaseSource = $followedSettings === null ? PlanetaryPriceSources::DEFAULT_PURCHASE : $followedSettings['purchaseSource'];
        $saleSource = $followedSettings === null ? PlanetaryPriceSources::DEFAULT_SALE : $followedSettings['saleSource'];

        $productTypeIds = [];
        $extractedTypeIds = [];
        $inputTypeIds = [];
        foreach ($items as $typeId => $item) {
            if ($item['tier'] === self::EXTRACTED_TIER) {
                $extractedTypeIds[] = $typeId;
            } elseif ($item['dailyQuantity'] > 0.0) {
                $productTypeIds[] = $typeId;
            }
            foreach (array_keys($item['inputs']) as $inputTypeId) {
                $inputTypeIds[$inputTypeId] = $inputTypeId;
            }
        }
        $inputTypeIds = array_values($inputTypeIds);
        $pricedTypeIds = array_values(array_unique([...$productTypeIds, ...$inputTypeIds]));

        $hubSellPrices = $this->structureMarketService->getLowestSellPrices($hubId, $pricedTypeIds);
        $jitaSellPrices = $this->jitaSellPrices($purchaseSource, $saleSource, $pricedTypeIds);
        $jitaBuyPrices = $this->jitaBuyPrices($purchaseSource, $saleSource, $pricedTypeIds);
        $paidPrices = array_column($paidReport['prices'], 'paidPrice', 'typeId');

        $purchasePrices = [];
        foreach ($inputTypeIds as $inputTypeId) {
            $purchasePrices[$inputTypeId] = PlanetaryPriceSources::purchase(
                $purchaseSource,
                $paidPrices[$inputTypeId] ?? null,
                $hubSellPrices[$inputTypeId] ?? null,
                $jitaSellPrices[$inputTypeId] ?? null,
                $jitaBuyPrices[$inputTypeId] ?? null,
            );
        }

        $costing = new PlanetaryProductionCosting(
            $items,
            $purchasePrices,
            $this->structureMarketService->getHighestBuyPrices($hubId, $extractedTypeIds),
        );

        $lines = [];
        foreach ($productTypeIds as $typeId) {
            $salePrice = PlanetaryPriceSources::sale($saleSource, $hubSellPrices[$typeId] ?? null, $jitaSellPrices[$typeId] ?? null, $jitaBuyPrices[$typeId] ?? null);
            $lines[] = $this->lineOf($items[$typeId], $typeId, $hubSellPrices[$typeId] ?? null, $salePrice, $costing);
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
            'sources' => ['purchase' => $purchaseSource, 'sale' => $saleSource, 'followedTemplateName' => $followed?->getName()],
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
     * @return array{typeId: int, typeName: string|null, tier: string, dailyQuantity: float, soldQuantity: float, internal: bool, hubSellPrice: float|null, salePrice: float|null, salesIsk: float|null, inputsIsk: float|null, profitPerDay: float|null, marginPercent: float|null, valuesExtractedAtHubBuy: bool, jitaIsk: float|null, ifSold: array{salesIsk: float|null, inputsIsk: float|null, profitPerDay: float|null, marginPercent: float|null}|null}
     */
    private function lineOf(array $item, int $typeId, ?float $hubSellPrice, ?float $salePrice, PlanetaryProductionCosting $costing): array
    {
        $sold = $item['sellable'];
        $internal = $sold <= 0.0;
        $unitCost = $internal ? null : $costing->unitCostOf($typeId);

        $salesIsk = $internal ? 0.0 : ($salePrice === null ? null : round($sold * $salePrice, 2));
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
            'salePrice' => $salePrice,
            'salesIsk' => $salesIsk,
            'inputsIsk' => $inputsIsk,
            'profitPerDay' => $profit,
            'marginPercent' => $profit !== null && $salesIsk > 0.0 ? round($profit / $salesIsk * self::PERCENT, 2) : null,
            'valuesExtractedAtHubBuy' => $unitCost !== null && $unitCost['extracted'],
            'jitaIsk' => $item['unitPrice'] === null ? null : $item['jitaIsk'],
            'ifSold' => $internal ? $this->marginIfSold($item['dailyQuantity'], $salePrice, $costing->unitCostOf($typeId)) : null,
        ];
    }

    /**
     * @param array{cost: float, extracted: bool}|null $unitCost
     * @return array{salesIsk: float|null, inputsIsk: float|null, profitPerDay: float|null, marginPercent: float|null}
     */
    private function marginIfSold(float $dailyQuantity, ?float $salePrice, ?array $unitCost): array
    {
        $salesIsk = $salePrice === null ? null : round($dailyQuantity * $salePrice, 2);
        $inputsIsk = $unitCost === null ? null : round($dailyQuantity * $unitCost['cost'], 2);
        $profit = $salesIsk === null || $inputsIsk === null ? null : round($salesIsk - $inputsIsk, 2);

        return [
            'salesIsk' => $salesIsk,
            'inputsIsk' => $inputsIsk,
            'profitPerDay' => $profit,
            'marginPercent' => $profit !== null && $salesIsk > 0.0 ? round($profit / $salesIsk * self::PERCENT, 2) : null,
        ];
    }

    /**
     * @param list<int> $typeIds
     * @return array<int, float|null>
     */
    private function jitaSellPrices(string $purchaseSource, string $saleSource, array $typeIds): array
    {
        $needed = in_array($purchaseSource, [PlanetaryPriceSources::JITA_SELL, PlanetaryPriceSources::BEST], true) || $saleSource === PlanetaryPriceSources::JITA_SELL;

        return $needed ? $this->jitaMarketService->getPrices($typeIds) : [];
    }

    /**
     * @param list<int> $typeIds
     * @return array<int, float|null>
     */
    private function jitaBuyPrices(string $purchaseSource, string $saleSource, array $typeIds): array
    {
        $needed = $purchaseSource === PlanetaryPriceSources::JITA_BUY || $saleSource === PlanetaryPriceSources::JITA_BUY;

        return $needed ? $this->jitaMarketService->getBuyPrices($typeIds) : [];
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
