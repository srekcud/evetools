<?php

declare(strict_types=1);

namespace App\Industry\Application;

use App\Industry\Domain\RigBonus;

final readonly class TimeRig
{
    /**
     * @param list<string> $targetedCategories
     */
    public function __construct(public RigBonus $timeBonus, public array $targetedCategories)
    {
    }

    public function targets(string $productCategory): bool
    {
        return \in_array($productCategory, $this->targetedCategories, true);
    }
}
