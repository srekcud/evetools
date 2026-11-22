<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use App\Entity\Sde\InvType;
use App\Industry\Domain\ActivityKind;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\RigCatalogue;

class RigOptionsProvider
{
    private const string THUKKER_CATEGORY = 'basic_capital_component';

    private const float NO_BONUS = 0.0;

    public function __construct(private readonly InvTypeRepository $invTypeRepository, private readonly SdeRigBonusReader $rigBonusReader)
    {
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function getRigOptionsArray(): array
    {
        $rigNames = [];
        foreach (RigCatalogue::RIGS_BY_FAMILY as $rigs) {
            foreach ($rigs as $rig) {
                $rigNames[] = $rig['name'];
            }
        }
        $rigTypes = $this->invTypeRepository->findByNames($rigNames);

        $options = [];
        foreach (RigCatalogue::RIGS_BY_FAMILY as $family => $rigs) {
            $activity = 'reaction' === $family ? ActivityKind::Reaction : ActivityKind::Manufacturing;
            $options[$family] = [];
            foreach ($rigs as $rig) {
                if (\array_key_exists($rig['name'], $rigTypes)) {
                    $options[$family][] = $this->optionOf($rig, $rigTypes[$rig['name']], $activity);
                }
            }
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $rig
     *
     * @return array<string, mixed>
     */
    private function optionOf(array $rig, InvType $rigType, ActivityKind $activity): array
    {
        $rigTypeId = $rigType->getTypeId();
        $genericBonus = $this->rigBonusReader->materialBonusOf($rigTypeId, $activity);
        $thukkerBonus = ActivityKind::Manufacturing === $activity ? $this->rigBonusReader->thukkerMaterialBonusOf($rigTypeId) : null;
        $timeBonus = $this->rigBonusReader->timeBonusOf($rigTypeId, $activity)->reductionPercent ?? self::NO_BONUS;
        $costBonus = $this->rigBonusReader->costBonusOf($rigTypeId)->reductionPercent ?? self::NO_BONUS;

        $option = $rig + ['bonus' => ($genericBonus ?? $thukkerBonus)->reductionPercent ?? self::NO_BONUS];
        if ($timeBonus > self::NO_BONUS) {
            $option['timeBonus'] = $timeBonus;
        }
        if ($costBonus > self::NO_BONUS) {
            $option['costBonus'] = $costBonus;
        }
        if (null !== $genericBonus && null !== $thukkerBonus && \in_array(self::THUKKER_CATEGORY, $rig['targetCategories'], true)) {
            $option['categoryBonuses'] = [self::THUKKER_CATEGORY => $thukkerBonus->reductionPercent];
        }

        return $option;
    }
}
