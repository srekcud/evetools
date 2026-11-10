<?php

declare(strict_types=1);

namespace App\Service\Industry\Blueprint;

use App\Entity\OwnedBlueprint;
use App\Entity\User;
use App\Industry\Application\OwnedBlueprintUse;
use App\Industry\Domain\BlueprintEfficiency;
use App\Industry\Domain\MaterialEfficiency;
use App\Industry\Domain\TimeEfficiency;
use App\Repository\CorpAssetVisibilityRepository;
use App\Repository\OwnedBlueprintRepository;

class OriginalBlueprintFinder
{
    public function __construct(
        private readonly OwnedBlueprintRepository $ownedBlueprintRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
    ) {
    }

    /** @return list<int> */
    public function typeIdsOwnedBy(User $user): array
    {
        return array_values(array_unique(array_map(
            static fn (OwnedBlueprint $blueprint): int => $blueprint->getTypeId(),
            array_filter($this->visibleBlueprintsOf($user), static fn (OwnedBlueprint $blueprint): bool => $blueprint->isOriginal()),
        )));
    }

    /** @return array<int, OwnedBlueprintUse> by blueprint typeId */
    public function bestUsesOwnedBy(User $user): array
    {
        $uses = [];
        foreach ($this->visibleBlueprintsOf($user) as $blueprint) {
            $use = new OwnedBlueprintUse(
                new BlueprintEfficiency(new MaterialEfficiency($blueprint->getMaterialEfficiency()), new TimeEfficiency($blueprint->getTimeEfficiency())),
                $blueprint->isOriginal(),
                $blueprint->getCharacter()?->getEveCharacterId(),
            );
            if (!isset($uses[$blueprint->getTypeId()]) || $use->isBetterThan($uses[$blueprint->getTypeId()])) {
                $uses[$blueprint->getTypeId()] = $use;
            }
        }

        return $uses;
    }

    /** @return list<OwnedBlueprint> */
    private function visibleBlueprintsOf(User $user): array
    {
        $blueprints = $this->ownedBlueprintRepository->findByCharacters($user->getCharacters()->getValues());

        $corporationId = $user->getMainCharacter()?->getCorporationId();
        if ($corporationId !== null) {
            $visibleDivisions = $this->visibilityRepository->findByCorporationId($corporationId)?->divisionsVisibleTo($user);
            if ($visibleDivisions !== null && $visibleDivisions !== []) {
                $blueprints = [
                    ...$blueprints,
                    ...array_filter(
                        $this->ownedBlueprintRepository->findByCorporationId($corporationId),
                        static fn (OwnedBlueprint $blueprint): bool => in_array($blueprint->getDivisionNumber(), $visibleDivisions, true),
                    ),
                ];
            }
        }

        return $blueprints;
    }
}
