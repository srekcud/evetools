<?php

declare(strict_types=1);

namespace App\Service\MoonTax;

use App\Enum\MoonTaxStatus;

final readonly class MoonTaxStatementView
{
    /**
     * @param list<int> $unknownTypeIds
     */
    public function __construct(
        public ?string $id,
        public int $corporationId,
        public int $structureId,
        public int $characterId,
        public \DateTimeImmutable $periodStart,
        public \DateTimeImmutable $periodEnd,
        public ?string $reference,
        public ?float $tax,
        public float $paid,
        public ?float $remaining,
        public MoonTaxStatus $status,
        public array $unknownTypeIds,
        public ?bool $outsideAlliance,
    ) {
    }
}
