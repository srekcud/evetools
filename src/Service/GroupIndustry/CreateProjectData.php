<?php

declare(strict_types=1);

namespace App\Service\GroupIndustry;

/**
 * Data transfer object for creating a Group Industry project.
 * Decoupled from API Platform input DTOs.
 */
final readonly class CreateProjectData
{
    /**
     * @param list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> $items
     * @param int[] $blacklistGroupIds
     * @param int[] $blacklistTypeIds
     * @param array<string, int>|null $lineRentalRatesOverride
     */
    public function __construct(
        public string $name,
        public array $items,
        public array $blacklistGroupIds = [],
        public array $blacklistTypeIds = [],
        public ?string $containerName = null,
        public ?array $lineRentalRatesOverride = null,
        public float $brokerFeePercent = 3.6,
        public float $salesTaxPercent = 3.6,
    ) {
    }
}
