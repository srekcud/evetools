<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

use App\Entity\User;

final readonly class ProjectSellStructures
{
    private const string UNNAMED_STRUCTURE_FORMAT = 'Structure %d';

    /** @return list<SellStructure> */
    public function selectableBy(User $user): array
    {
        $structures = [];

        $preferred = $this->defaultFor($user);
        if (null !== $preferred) {
            $structures[$preferred->id] = $preferred;
        }

        foreach ($user->getMarketStructures() as $marketStructure) {
            $structures[$marketStructure['id']] ??= new SellStructure($marketStructure['id'], $marketStructure['name']);
        }

        return array_values($structures);
    }

    public function defaultFor(User $user): ?SellStructure
    {
        $effectiveId = $user->getEffectiveMarketStructureId();
        if (null === $effectiveId) {
            return null;
        }

        return new SellStructure($effectiveId, $user->getEffectiveMarketStructureName() ?? \sprintf(self::UNNAMED_STRUCTURE_FORMAT, $effectiveId));
    }

    public function choose(User $user, int $structureId): SellStructure
    {
        foreach ($this->selectableBy($user) as $structure) {
            if ($structure->id === $structureId) {
                return $structure;
            }
        }

        throw new \InvalidArgumentException(\sprintf('Structure %d is not one of your market structures', $structureId));
    }
}
