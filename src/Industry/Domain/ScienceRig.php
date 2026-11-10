<?php

declare(strict_types=1);

namespace App\Industry\Domain;

final readonly class ScienceRig
{
    public function __construct(public ActivityKind $activity, public ?RigBonus $timeBonus, public ?RigBonus $costBonus)
    {
        if (!$activity->isLaboratory()) {
            throw new \InvalidArgumentException(\sprintf('A science rig applies to a laboratory activity, got %s.', $activity->name));
        }
        if (null === $timeBonus && null === $costBonus) {
            throw new \InvalidArgumentException('A science rig must carry a time bonus or a cost bonus.');
        }
    }
}
