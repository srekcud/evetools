<?php

declare(strict_types=1);

namespace App\State\Provider\Mining;

use App\ApiResource\Mining\ReprocessingProfileResource;
use App\Entity\ReprocessingProfile;
use App\Repository\Sde\InvTypeRepository;

final readonly class ReprocessingProfileResourceMapper
{
    public function __construct(
        private InvTypeRepository $invTypeRepository,
    ) {
    }

    public function fromProfile(?ReprocessingProfile $profile): ReprocessingProfileResource
    {
        $resource = new ReprocessingProfileResource();
        $resource->oreProcessingSkills = $this->invTypeRepository->findOreProcessingSkills();

        if ($profile === null) {
            return $resource;
        }

        $resource->configured = true;
        $resource->characterId = $profile->getCharacter()->getEveCharacterId();
        $resource->oreProcessingSkillTypeId = $profile->getOreProcessingSkillTypeId();
        $resource->location = $profile->getLocation()->value;
        $resource->rigTier = $profile->getRigTier()?->value;
        $resource->security = $profile->getSecurity()?->value;
        $resource->implantPercent = $profile->getImplantPercent();
        $resource->reprocessingLevel = $profile->getReprocessingLevel();
        $resource->reprocessingEfficiencyLevel = $profile->getReprocessingEfficiencyLevel();
        $resource->oreProcessingLevel = $profile->getOreProcessingLevel();
        $resource->reprocessYield = $profile->getReprocessYield();
        $resource->failure = $profile->getFailure()?->value;
        $resource->computedAt = $profile->getComputedAt();

        return $resource;
    }
}
