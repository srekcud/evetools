<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Entity\Sde\IndustryActivitySkill;
use App\Industry\Application\InventionSkillRequirement;
use App\Industry\Application\InventionSkillRequirements;
use App\Repository\Sde\IndustryActivitySkillRepository;
use App\Repository\Sde\InvTypeRepository;

final readonly class SdeInventionSkillRequirements implements InventionSkillRequirements
{
    private const int ACTIVITY_INVENTION = 8;
    private const int SCIENCE_GROUP_ID = 270;
    private const int MAXIMUM_SCIENCE_SKILLS = 2;
    private const array ENCRYPTION_SKILL_TYPE_IDS = [3408, 21790, 21791, 23087, 23121, 52308, 55025];

    public function __construct(
        private IndustryActivitySkillRepository $activitySkillRepository,
        private InvTypeRepository $invTypeRepository,
    ) {
    }

    public function of(int $t1BlueprintTypeId): InventionSkillRequirement
    {
        $requiredSkillTypeIds = array_map(
            static fn (IndustryActivitySkill $requirement): int => $requirement->getSkillId(),
            $this->activitySkillRepository->findByBlueprintAndActivity($t1BlueprintTypeId, self::ACTIVITY_INVENTION),
        );
        $types = [] === $requiredSkillTypeIds ? [] : $this->invTypeRepository->findByTypeIds($requiredSkillTypeIds);

        $encryptionSkillTypeIds = [];
        $scienceSkillTypeIds = [];
        foreach ($requiredSkillTypeIds as $skillTypeId) {
            if (\in_array($skillTypeId, self::ENCRYPTION_SKILL_TYPE_IDS, true)) {
                $encryptionSkillTypeIds[] = $skillTypeId;
            } elseif (self::SCIENCE_GROUP_ID === ($types[$skillTypeId] ?? null)?->getGroup()->getGroupId()) {
                $scienceSkillTypeIds[] = $skillTypeId;
            }
        }

        if (\count($scienceSkillTypeIds) > self::MAXIMUM_SCIENCE_SKILLS || \count($encryptionSkillTypeIds) > 1) {
            throw new \UnexpectedValueException(\sprintf('Blueprint %d requires %d science and %d encryption skills for its invention: the SDE is inconsistent.', $t1BlueprintTypeId, \count($scienceSkillTypeIds), \count($encryptionSkillTypeIds)));
        }

        return new InventionSkillRequirement($scienceSkillTypeIds, $encryptionSkillTypeIds[0] ?? null);
    }
}
