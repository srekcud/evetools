<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\Character;
use App\Industry\Application\InventionCatalog;
use App\Industry\Application\InventionSkillLevels;
use App\Industry\Application\InventionSkillRequirements;
use App\Repository\CachedCharacterSkillRepository;

class InventorSkills
{
    private const int UNUSED_SKILL_LEVEL = 0;

    public function __construct(
        private readonly InventionCatalog $inventions,
        private readonly InventionSkillRequirements $requirements,
        private readonly CachedCharacterSkillRepository $skillRepository,
    ) {
    }

    public function levelsFor(int $productTypeId, ?Character $inventor): InventionSkillLevels
    {
        $recipe = null === $inventor ? null : $this->inventions->inventionOf($productTypeId);
        if (null === $inventor || null === $recipe) {
            return InventionSkillLevels::none();
        }

        $requirement = $this->requirements->of($recipe->t1BlueprintTypeId);
        $cachedSkills = $this->skillRepository->findAllSkillsForCharacter($inventor);
        $levelOf = static fn (?int $skillTypeId): ?int => null === $skillTypeId
            ? self::UNUSED_SKILL_LEVEL
            : (isset($cachedSkills[$skillTypeId]) ? $cachedSkills[$skillTypeId]->getLevel() : null);

        return new InventionSkillLevels(
            $levelOf($requirement->scienceSkillTypeIds[0] ?? null),
            $levelOf($requirement->scienceSkillTypeIds[1] ?? null),
            $levelOf($requirement->encryptionSkillTypeId),
        );
    }
}
