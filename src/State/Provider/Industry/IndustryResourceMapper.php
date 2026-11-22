<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use App\ApiResource\Industry\CharacterSkillResource;
use App\ApiResource\Industry\StructureConfigResource;
use App\Entity\CachedCharacterSkill;
use App\Entity\Character;
use App\Entity\IndustryStructureConfig;
use App\Industry\Infrastructure\Doctrine\StructureBonusSummary;

class IndustryResourceMapper
{
    public function __construct(private readonly StructureBonusSummary $structureBonusSummary)
    {
    }

    public function structureToResource(IndustryStructureConfig $structure): StructureConfigResource
    {
        $resource = new StructureConfigResource();
        $resource->id = $structure->getId()->toRfc4122();
        $resource->name = $structure->getName();
        $resource->locationId = $structure->getLocationId();
        $resource->solarSystemId = $structure->getSolarSystemId();
        $resource->securityType = $structure->getSecurityType();
        $resource->structureType = $structure->getStructureType();
        $resource->rigs = $structure->getRigs();
        $resource->isDefault = $structure->isDefault();
        $resource->isCorporationStructure = $structure->isCorporationStructure();
        $resource->facilityTaxRate = $structure->getFacilityTaxPercent();
        $bonuses = $this->structureBonusSummary->of($structure->getStructureType(), $structure->getSecurityType(), $structure->getRigs());
        $resource->manufacturingMaterialBonus = $bonuses->manufacturingMaterialBonus;
        $resource->reactionMaterialBonus = $bonuses->reactionMaterialBonus;
        $resource->manufacturingTimeBonus = $bonuses->manufacturingTimeBonus;
        $resource->reactionTimeBonus = $bonuses->reactionTimeBonus;
        $resource->unrecognisedRigs = $bonuses->unrecognisedRigs;
        $resource->hullToSpecify = $bonuses->hullToSpecify;
        $resource->createdAt = $structure->getCreatedAt()->format('c');

        return $resource;
    }

    /**
     * @param CachedCharacterSkill[] $skills
     */
    public function characterSkillsToResource(Character $character, array $skills): CharacterSkillResource
    {
        $resource = new CharacterSkillResource();
        $resource->characterId = $character->getId()?->toRfc4122() ?? '';
        $resource->characterName = $character->getName();

        $hasEsi = false;
        $lastSync = null;

        foreach ($skills as $skill) {
            match ($skill->getSkillId()) {
                CachedCharacterSkill::SKILL_INDUSTRY => $resource->industry = $skill->getLevel(),
                CachedCharacterSkill::SKILL_ADVANCED_INDUSTRY => $resource->advancedIndustry = $skill->getLevel(),
                CachedCharacterSkill::SKILL_REACTIONS => $resource->reactions = $skill->getLevel(),
                default => null,
            };
            if (!$skill->isManual()) {
                $hasEsi = true;
            }
            if ($lastSync === null || $skill->getCachedAt() > $lastSync) {
                $lastSync = $skill->getCachedAt();
            }
        }

        $resource->source = $hasEsi ? 'esi' : (empty($skills) ? 'none' : 'manual');
        $resource->lastSyncAt = $lastSync?->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
