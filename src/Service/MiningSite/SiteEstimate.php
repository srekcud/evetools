<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final readonly class SiteEstimate
{
    /**
     * @param array<int, SiteTypeEstimate> $types
     * @param list<int>                    $notDepletingTypeIds
     * @param list<int>                    $missingPriceTypeIds
     */
    public function __construct(
        public array $types,
        public ?\DateTimeImmutable $recentEta,
        public ?\DateTimeImmutable $trendEta,
        public array $notDepletingTypeIds,
        public ?float $remainingValue,
        public array $missingPriceTypeIds,
        public ?int $mostProfitableTypeId,
        public bool $allDepleted,
    ) {
    }
}
