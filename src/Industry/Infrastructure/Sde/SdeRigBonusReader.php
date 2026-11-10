<?php

declare(strict_types=1);

namespace App\Industry\Infrastructure\Sde;

use App\Entity\Sde\DgmTypeEffect;
use App\Industry\Domain\ActivityKind;
use App\Industry\Domain\RigBonus;
use App\Industry\Domain\RigSecurityModifiers;
use App\Industry\Domain\ScienceRig;
use App\Repository\Sde\DgmTypeEffectRepository;

/**
 * Rig bonuses read from the dogma attributes of the rig type (spec R4, D10). The SDE stores a reduction as a
 * negative percent; RigBonus carries it as a positive one. An absent attribute is null, never a default.
 */
final readonly class SdeRigBonusReader
{
    private const int ATTRIBUTE_HI_SEC_MODIFIER = 2355;
    private const int ATTRIBUTE_LOW_SEC_MODIFIER = 2356;
    private const int ATTRIBUTE_NULL_SEC_MODIFIER = 2357;
    private const int ATTRIBUTE_ENG_RIG_TIME_BONUS = 2593;
    private const int ATTRIBUTE_ENG_RIG_MAT_BONUS = 2594;
    private const int ATTRIBUTE_ENG_RIG_COST_BONUS = 2595;
    private const int ATTRIBUTE_THUKKER_ENG_RIG_MAT_BONUS = 2653;
    private const int ATTRIBUTE_REF_RIG_TIME_BONUS = 2713;
    private const int ATTRIBUTE_REF_RIG_MAT_BONUS = 2714;
    private const int EFFECT_INVENTION_COST = 6830;
    private const int EFFECT_INVENTION_TIME = 6831;
    private const int EFFECT_RESEARCH_MATERIAL_COST = 6832;
    private const int EFFECT_RESEARCH_MATERIAL_TIME = 6833;
    private const int EFFECT_RESEARCH_TIME_COST = 6834;
    private const int EFFECT_RESEARCH_TIME_TIME = 6835;
    private const int EFFECT_COPY_COST = 6836;
    private const int EFFECT_COPY_TIME = 6837;

    private const array SCIENCE_EFFECTS_BY_ACTIVITY = [
        [ActivityKind::Copying, self::EFFECT_COPY_COST, self::EFFECT_COPY_TIME],
        [ActivityKind::Invention, self::EFFECT_INVENTION_COST, self::EFFECT_INVENTION_TIME],
        [ActivityKind::ResearchMaterial, self::EFFECT_RESEARCH_MATERIAL_COST, self::EFFECT_RESEARCH_MATERIAL_TIME],
        [ActivityKind::ResearchTime, self::EFFECT_RESEARCH_TIME_COST, self::EFFECT_RESEARCH_TIME_TIME],
    ];

    private const array SCIENCE_EFFECT_IDS = [
        self::EFFECT_COPY_COST,
        self::EFFECT_COPY_TIME,
        self::EFFECT_INVENTION_COST,
        self::EFFECT_INVENTION_TIME,
        self::EFFECT_RESEARCH_MATERIAL_COST,
        self::EFFECT_RESEARCH_MATERIAL_TIME,
        self::EFFECT_RESEARCH_TIME_COST,
        self::EFFECT_RESEARCH_TIME_TIME,
    ];

    public function __construct(private DogmaAttributeValues $dogmaAttributeValues, private DgmTypeEffectRepository $typeEffectRepository)
    {
    }

    /**
     * @return list<ScienceRig>
     */
    public function scienceRigsOf(int $rigTypeId): array
    {
        $effectIds = $this->effectIdsOf($rigTypeId);

        $scienceRigs = [];
        foreach (self::SCIENCE_EFFECTS_BY_ACTIVITY as [$activity, $costEffectId, $timeEffectId]) {
            $costBonus = \in_array($costEffectId, $effectIds, true) ? $this->requiredBonusOf($rigTypeId, self::ATTRIBUTE_ENG_RIG_COST_BONUS) : null;
            $timeBonus = \in_array($timeEffectId, $effectIds, true) ? $this->requiredBonusOf($rigTypeId, self::ATTRIBUTE_ENG_RIG_TIME_BONUS) : null;
            if (null !== $costBonus || null !== $timeBonus) {
                $scienceRigs[] = new ScienceRig($activity, $timeBonus, $costBonus);
            }
        }

        return $scienceRigs;
    }

    public function materialBonusOf(int $rigTypeId, ActivityKind $activity): ?RigBonus
    {
        if ($this->carriesScienceEffect($rigTypeId)) {
            return null;
        }

        return match ($activity) {
            ActivityKind::Manufacturing => $this->bonusOf($rigTypeId, self::ATTRIBUTE_ENG_RIG_MAT_BONUS),
            ActivityKind::Reaction => $this->bonusOf($rigTypeId, self::ATTRIBUTE_REF_RIG_MAT_BONUS),
            default => throw new \InvalidArgumentException(\sprintf('Rigs give no material bonus to %s.', $activity->name)),
        };
    }

    public function timeBonusOf(int $rigTypeId, ActivityKind $activity): ?RigBonus
    {
        return match ($activity) {
            ActivityKind::Manufacturing => $this->bonusOf($rigTypeId, self::ATTRIBUTE_ENG_RIG_TIME_BONUS),
            ActivityKind::Reaction => $this->bonusOf($rigTypeId, self::ATTRIBUTE_REF_RIG_TIME_BONUS),
            default => throw new \InvalidArgumentException(\sprintf('Rigs give no time bonus to %s.', $activity->name)),
        };
    }

    public function costBonusOf(int $rigTypeId): ?RigBonus
    {
        return $this->bonusOf($rigTypeId, self::ATTRIBUTE_ENG_RIG_COST_BONUS);
    }

    public function thukkerMaterialBonusOf(int $rigTypeId): ?RigBonus
    {
        return $this->bonusOf($rigTypeId, self::ATTRIBUTE_THUKKER_ENG_RIG_MAT_BONUS);
    }

    private function carriesScienceEffect(int $rigTypeId): bool
    {
        return [] !== array_intersect($this->effectIdsOf($rigTypeId), self::SCIENCE_EFFECT_IDS);
    }

    /**
     * @return list<int>
     */
    private function effectIdsOf(int $rigTypeId): array
    {
        return array_values(array_map(
            static fn (DgmTypeEffect $typeEffect): int => $typeEffect->getEffectId(),
            $this->typeEffectRepository->findByTypeId($rigTypeId),
        ));
    }

    private function requiredBonusOf(int $rigTypeId, int $bonusAttributeId): RigBonus
    {
        return $this->bonusOf($rigTypeId, $bonusAttributeId)
            ?? throw new \UnexpectedValueException(\sprintf('Rig %d carries a science effect but no value for dogma attribute %d.', $rigTypeId, $bonusAttributeId));
    }

    private function bonusOf(int $rigTypeId, int $bonusAttributeId): ?RigBonus
    {
        $values = $this->dogmaAttributeValues->of($rigTypeId, [
            $bonusAttributeId,
            self::ATTRIBUTE_HI_SEC_MODIFIER,
            self::ATTRIBUTE_LOW_SEC_MODIFIER,
            self::ATTRIBUTE_NULL_SEC_MODIFIER,
        ]);
        if (!\array_key_exists($bonusAttributeId, $values)) {
            return null;
        }

        return new RigBonus(
            -$values[$bonusAttributeId],
            new RigSecurityModifiers(
                $values[self::ATTRIBUTE_HI_SEC_MODIFIER] ?? null,
                $values[self::ATTRIBUTE_LOW_SEC_MODIFIER] ?? null,
                $values[self::ATTRIBUTE_NULL_SEC_MODIFIER] ?? null,
            ),
        );
    }
}
