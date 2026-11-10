<?php

declare(strict_types=1);

namespace App\Service\Industry\ContractSales;

use App\Entity\PublicContractListing;

class ContractSalesAnalyzer
{
    public const int WINDOW_DAYS = 30;
    public const int REPUBLICATION_WINDOW_HOURS = 24;
    public const int MIN_SALES_FOR_MEDIUM_CONFIDENCE = 5;
    public const int MIN_HISTORY_DAYS_FOR_MEDIUM_CONFIDENCE = 7;
    private const int SECONDS_PER_DAY = 86400;

    /**
     * @param list<PublicContractListing> $regionListings
     */
    public function outcomeOf(PublicContractListing $listing, array $regionListings): ContractSaleOutcome
    {
        return $this->outcomeAmong($listing, $this->groupedByIssuerAndItems($regionListings));
    }

    /**
     * @param list<PublicContractListing> $regionListings
     * @param array<int, HullAttribution> $hullAttributions
     * @return array<int, ContractSalesIndicators>
     */
    public function indicators(array $regionListings, \DateTimeImmutable $now, array $hullAttributions = []): array
    {
        if ([] === $regionListings) {
            return [];
        }

        $windowStart = $now->modify('-' . self::WINDOW_DAYS . ' days');
        $groups = $this->groupedByIssuerAndItems($regionListings);
        $historyStart = min(array_map(static fn (PublicContractListing $listing): \DateTimeImmutable => $listing->getFirstSeenAt(), $regionListings));
        $isHistoryLongEnough = $now >= $historyStart->modify('+' . self::MIN_HISTORY_DAYS_FOR_MEDIUM_CONFIDENCE . ' days');

        $sales = [];
        $expiredCounts = [];
        foreach ($regionListings as $listing) {
            $typeId = $listing->getTypeId();
            $quantity = $listing->getQuantity();
            $price = $listing->getPrice();
            $hull = $hullAttributions[$listing->getContractId()] ?? null;
            if (null === $typeId && null !== $hull) {
                $typeId = $hull->typeId;
                $quantity = 1;
                $price = $hull->price;
            }
            $disappearedAt = $listing->getDisappearedAt();
            if (null === $typeId || null === $quantity || null === $disappearedAt || !$listing->isDisappearanceConfirmed() || $disappearedAt < $windowStart) {
                continue;
            }

            match ($this->outcomeAmong($listing, $groups)) {
                ContractSaleOutcome::ProbablySold => $sales[$typeId][] = [
                    'quantity' => $quantity,
                    'unitPrice' => $price / $quantity,
                    'daysToSell' => ($disappearedAt->getTimestamp() - $listing->getFirstSeenAt()->getTimestamp()) / self::SECONDS_PER_DAY,
                ],
                ContractSaleOutcome::Expired => $expiredCounts[$typeId] = ($expiredCounts[$typeId] ?? 0) + 1,
                default => null,
            };
        }

        $indicators = [];
        foreach (array_unique([...array_keys($sales), ...array_keys($expiredCounts)]) as $typeId) {
            $indicators[$typeId] = $this->indicatorsOf($typeId, $sales[$typeId] ?? [], $expiredCounts[$typeId] ?? 0, $isHistoryLongEnough);
        }

        return $indicators;
    }

    /**
     * @param list<array{quantity: int, unitPrice: float, daysToSell: float}> $sold
     */
    private function indicatorsOf(int $typeId, array $sold, int $expiredCount, bool $isHistoryLongEnough): ContractSalesIndicators
    {
        $soldCount = count($sold);
        $closedCount = $soldCount + $expiredCount;
        $isConfident = $soldCount >= self::MIN_SALES_FOR_MEDIUM_CONFIDENCE && $isHistoryLongEnough;

        return new ContractSalesIndicators(
            $typeId,
            $soldCount,
            array_sum(array_column($sold, 'quantity')),
            $closedCount > 0 ? $soldCount / $closedCount : null,
            [] === $sold ? null : $this->median(array_column($sold, 'unitPrice')),
            [] === $sold ? null : $this->median(array_column($sold, 'daysToSell')),
            $isConfident ? ContractSalesConfidence::Medium : ContractSalesConfidence::Low,
        );
    }

    /**
     * @param array<string, list<PublicContractListing>> $groups
     */
    private function outcomeAmong(PublicContractListing $listing, array $groups): ContractSaleOutcome
    {
        $disappearedAt = $listing->getDisappearedAt();
        if (null === $disappearedAt) {
            return ContractSaleOutcome::Listed;
        }
        if (!$listing->isDisappearanceConfirmed()) {
            return ContractSaleOutcome::Pending;
        }
        if ($disappearedAt >= $listing->getDateExpired()) {
            return ContractSaleOutcome::Expired;
        }

        $republicationDeadline = $disappearedAt->modify('+' . self::REPUBLICATION_WINDOW_HOURS . ' hours');
        foreach ($groups[$this->groupKey($listing)] ?? [] as $candidate) {
            if ($candidate->getContractId() !== $listing->getContractId()
                && $candidate->getFirstSeenAt() >= $listing->getLastSeenAt()
                && $candidate->getFirstSeenAt() <= $republicationDeadline) {
                return ContractSaleOutcome::Republished;
            }
        }

        return ContractSaleOutcome::ProbablySold;
    }

    /**
     * @param list<PublicContractListing> $listings
     * @return array<string, list<PublicContractListing>>
     */
    private function groupedByIssuerAndItems(array $listings): array
    {
        $groups = [];
        foreach ($listings as $listing) {
            $groups[$this->groupKey($listing)][] = $listing;
        }

        return $groups;
    }

    private function groupKey(PublicContractListing $listing): string
    {
        return $listing->getIssuerId() . '|' . $listing->getItemsSignature();
    }

    /**
     * @param non-empty-list<float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return 1 === count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
