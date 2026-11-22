<?php

declare(strict_types=1);

namespace App\Industry\Application;

final readonly class StructureBonuses
{
    /**
     * @param list<string> $unrecognisedRigs
     */
    public function __construct(
        public float $manufacturingMaterialBonus,
        public float $reactionMaterialBonus,
        public ?float $manufacturingTimeBonus,
        public ?float $reactionTimeBonus,
        public array $unrecognisedRigs,
        public bool $hullToSpecify,
    ) {
    }
}
