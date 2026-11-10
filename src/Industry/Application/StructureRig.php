<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\RigBonus;

/**
 * Material rig fitted on a production structure, with the IndustryRigCategory values it targets (D10).
 */
final readonly class StructureRig
{
    /**
     * @param list<string> $targetedCategories
     */
    public function __construct(public RigBonus $materialBonus, public array $targetedCategories)
    {
    }

    public function targets(string $productCategory): bool
    {
        return \in_array($productCategory, $this->targetedCategories, true);
    }
}
