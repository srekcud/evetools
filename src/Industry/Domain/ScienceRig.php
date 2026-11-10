<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class ScienceRig
{
    public function __construct(public ActivityKind $activity, public ?RigBonus $timeBonus, public ?RigBonus $costBonus)
    {
    }
}
