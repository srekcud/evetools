<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\IndustryProject;
use App\Entity\IndustryProjectTemplate;

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
        public ?float $brokerFeePercent = null,
        public ?float $salesTaxPercent = null,
        public bool $private = true,
        public bool $acceptsOutsideFavoriteSystem = false,
        public float $minimumOutsideSaving = IndustryProject::DEFAULT_MINIMUM_OUTSIDE_SAVING,
        public ?IndustryProjectTemplate $template = null,
        public ?SellStructure $sellStructure = null,
        public bool $personalUse = false,
        public bool $excludesInvention = false,
    ) {
    }
}
