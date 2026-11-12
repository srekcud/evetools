<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

final readonly class ProductionOpportunity
{
    /**
     * @param list<MissingSkill> $missingSkills
     */
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public string $categoryLabel,
        public string $market,
        public float $sellPrice,
        public float $dailyVolume,
        public float $unitCost,
        public float $profitPerUnit,
        public ?float $marginPercent,
        public ?int $weeklyQuantity,
        public ?CapacityLimit $limitedBy,
        public ?float $weeklyProfit,
        public ?float $iskPerSlotHour,
        public array $missingSkills,
        public bool $groupHint,
    ) {
    }
}
