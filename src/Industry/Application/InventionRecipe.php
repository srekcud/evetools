<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\RecipeMaterial;
use App\Industry\Domain\Runs;

/**
 * How a T2 blueprint copy is invented (spec R8): from a 1-run copy of the T1 blueprint, with datacores.
 */
final readonly class InventionRecipe
{
    /**
     * @param ?float               $baseProbability null when the SDE has none (#74)
     * @param Runs                 $baseRuns        runs of the invented BPC, before the decryptor
     * @param list<RecipeMaterial> $datacores       consumed by each attempt
     */
    public function __construct(
        public int $t1BlueprintTypeId,
        public int $t1ProductTypeId,
        public ?float $baseProbability,
        public Runs $baseRuns,
        public array $datacores,
    ) {
    }
}
