<?php

declare(strict_types=1);

namespace App\Service\Planetary;

use App\Entity\CachedWalletTransaction;
use App\Entity\Character;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Repository\CachedWalletTransactionRepository;
use App\Repository\PlanetaryColonyRepository;
use App\Repository\Sde\PlanetSchematicTypeRepository;

final readonly class PlanetaryRevenueCalculator
{
    private const PERCENT = 100;
    private const TIERS = ['P1', 'P2', 'P3', 'P4'];

    public function __construct(
        private CachedWalletTransactionRepository $transactionRepository,
        private PlanetaryColonyRepository $colonyRepository,
        private PlanetSchematicTypeRepository $schematicTypeRepository,
        private PlanetaryProductionCalculator $productionCalculator,
    ) {
    }

    /**
     * @return array{
     *     periodEnd: string,
     *     days: int,
     *     tiers: list<array{tier: string, realisedIsk: float, change: float|null, daily: list<array{date: string, amount: float}>, contributors: list<array{characterId: int, characterName: string, value: float}>}>,
     *     realisedIsk: float,
     *     realisedChange: float|null,
     *     realisedDaily: list<array{date: string, amount: float}>,
     *     realisedContributors: list<array{characterId: int, characterName: string, value: float}>,
     *     estimate: array{dailyIsk: float, periodIsk: float, contributors: list<array{characterId: int, characterName: string, value: float}>},
     *     toFix: list<array{characterId: int, characterName: string, planetId: int, planetName: string|null, solarSystemName: string|null}>
     * }
     */
    public function calculate(User $user, \DateTimeImmutable $now, int $days): array
    {
        $periodEnd = $now->setTime(0, 0)->modify('+1 day');
        $periodStart = $periodEnd->modify("-{$days} days");
        $previousStart = $periodStart->modify("-{$days} days");

        $characters = $user->getCharacters()->toArray();
        $colonies = $this->colonyRepository->findByUser($user);
        $tierByType = $this->productTiers();

        $sales = $this->transactionRepository->findSalesByTypeIdsBetween($characters, array_keys($tierByType), $previousStart, $periodEnd);
        $current = array_filter($sales, static fn (CachedWalletTransaction $sale): bool => $sale->getDate() >= $periodStart);
        $previous = array_filter($sales, static fn (CachedWalletTransaction $sale): bool => $sale->getDate() < $periodStart);

        $tiers = [];
        foreach (self::TIERS as $tier) {
            $inTier = static fn (CachedWalletTransaction $sale): bool => ($tierByType[$sale->getTypeId()] ?? null) === $tier;
            $tierCurrent = array_filter($current, $inTier);
            $tiers[] = [
                'tier' => $tier,
                'realisedIsk' => $this->total($tierCurrent),
                'change' => $this->change($this->total($tierCurrent), $this->total(array_filter($previous, $inTier))),
                'daily' => $this->daily($tierCurrent),
                'contributors' => $this->contributors($tierCurrent),
            ];
        }

        $estimatedDailyIsk = $this->productionCalculator->calculateTotalDailyIsk($colonies);

        return [
            'periodEnd' => $now->format('Y-m-d'),
            'days' => $days,
            'tiers' => $tiers,
            'realisedIsk' => $this->total($current),
            'realisedChange' => $this->change($this->total($current), $this->total($previous)),
            'realisedDaily' => $this->daily($current),
            'realisedContributors' => $this->contributors($current),
            'estimate' => [
                'dailyIsk' => $estimatedDailyIsk,
                'periodIsk' => round($estimatedDailyIsk * $days, 2),
                'contributors' => $this->estimateContributors($colonies, $days),
            ],
            'toFix' => $this->coloniesWithoutSale($colonies, $current),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function productTiers(): array
    {
        $tierByType = [];
        foreach ($this->schematicTypeRepository->getSchematicMap() as $schematic) {
            $output = $schematic['output'];
            if ($output === null) {
                continue;
            }
            $tier = $this->productionCalculator->findProductTier($output['typeId']);
            if ($tier !== null) {
                $tierByType[$output['typeId']] = $tier;
            }
        }

        return $tierByType;
    }

    /**
     * @param iterable<CachedWalletTransaction> $sales
     */
    private function total(iterable $sales): float
    {
        $total = 0.0;
        foreach ($sales as $sale) {
            $total += $sale->getQuantity() * $sale->getUnitPrice();
        }

        return round($total, 2);
    }

    private function change(float $current, float $previous): ?float
    {
        if ($previous <= 0.0) {
            return null;
        }

        return round(($current - $previous) / $previous * self::PERCENT, 1);
    }

    /**
     * @param iterable<CachedWalletTransaction> $sales
     * @return list<array{date: string, amount: float}>
     */
    private function daily(iterable $sales): array
    {
        $amountByDate = [];
        foreach ($sales as $sale) {
            $date = $sale->getDate()->format('Y-m-d');
            $amountByDate[$date] = ($amountByDate[$date] ?? 0.0) + $sale->getQuantity() * $sale->getUnitPrice();
        }
        ksort($amountByDate);

        return array_map(
            static fn (string $date, float $amount): array => ['date' => $date, 'amount' => round($amount, 2)],
            array_keys($amountByDate),
            array_values($amountByDate),
        );
    }

    /**
     * @param iterable<CachedWalletTransaction> $sales
     * @return list<array{characterId: int, characterName: string, value: float}>
     */
    private function contributors(iterable $sales): array
    {
        $byCharacter = [];
        foreach ($sales as $sale) {
            $character = $sale->getCharacter();
            $eveCharacterId = $character->getEveCharacterId();
            $byCharacter[$eveCharacterId] ??= ['characterId' => $eveCharacterId, 'characterName' => $character->getName(), 'value' => 0.0];
            $byCharacter[$eveCharacterId]['value'] += $sale->getQuantity() * $sale->getUnitPrice();
        }

        return $this->rankedContributors($byCharacter);
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @return list<array{characterId: int, characterName: string, value: float}>
     */
    private function estimateContributors(array $colonies, int $days): array
    {
        $coloniesByCharacter = [];
        $characters = [];
        foreach ($colonies as $colony) {
            $character = $colony->getCharacter();
            $coloniesByCharacter[$character->getEveCharacterId()][] = $colony;
            $characters[$character->getEveCharacterId()] = $character;
        }

        $byCharacter = [];
        foreach ($coloniesByCharacter as $eveCharacterId => $characterColonies) {
            $byCharacter[$eveCharacterId] = [
                'characterId' => $eveCharacterId,
                'characterName' => $characters[$eveCharacterId]->getName(),
                'value' => $this->productionCalculator->calculateTotalDailyIsk($characterColonies) * $days,
            ];
        }

        return $this->rankedContributors($byCharacter);
    }

    /**
     * @param array<int, array{characterId: int, characterName: string, value: float}> $byCharacter
     * @return list<array{characterId: int, characterName: string, value: float}>
     */
    private function rankedContributors(array $byCharacter): array
    {
        $contributors = array_map(
            static fn (array $contributor): array => [...$contributor, 'value' => round($contributor['value'], 2)],
            array_values($byCharacter),
        );
        usort($contributors, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $contributors;
    }

    /**
     * @param PlanetaryColony[] $colonies
     * @param iterable<CachedWalletTransaction> $sales
     * @return list<array{characterId: int, characterName: string, planetId: int, planetName: string|null, solarSystemName: string|null}>
     */
    private function coloniesWithoutSale(array $colonies, iterable $sales): array
    {
        $sellingCharacterIds = [];
        foreach ($sales as $sale) {
            $sellingCharacterIds[$sale->getCharacter()->getEveCharacterId()] = true;
        }

        $toFix = [];
        foreach ($colonies as $colony) {
            $character = $colony->getCharacter();
            if (isset($sellingCharacterIds[$character->getEveCharacterId()]) || !$this->isProducing($colony)) {
                continue;
            }
            $toFix[] = [
                'characterId' => $character->getEveCharacterId(),
                'characterName' => $character->getName(),
                'planetId' => $colony->getPlanetId(),
                'planetName' => $colony->getPlanetName(),
                'solarSystemName' => $colony->getSolarSystemName(),
            ];
        }

        return $toFix;
    }

    private function isProducing(PlanetaryColony $colony): bool
    {
        foreach ($colony->getPins() as $pin) {
            if ($pin->isFactory() || ($pin->isExtractor() && !$pin->isExpired())) {
                return true;
            }
        }

        return false;
    }
}
