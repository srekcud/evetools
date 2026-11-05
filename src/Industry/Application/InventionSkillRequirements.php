<?php

declare(strict_types=1);

namespace App\Industry\Application;

interface InventionSkillRequirements
{
    public function of(int $t1BlueprintTypeId): InventionSkillRequirement;
}
