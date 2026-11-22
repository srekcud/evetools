<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Entity\Sde\DgmTypeAttribute;
use App\Entity\Sde\DgmTypeEffect;
use App\Entity\Sde\InvGroup;
use App\Entity\Sde\InvType;
use App\Industry\Application\StructureBonuses;
use App\Industry\Infrastructure\Doctrine\StructureBonusSummary;
use App\Industry\Infrastructure\Sde\DogmaAttributeValues;
use App\Industry\Infrastructure\Sde\SdeRigBonusReader;
use App\Industry\Infrastructure\Sde\SdeStructureBonusReader;
use App\Repository\Sde\DgmTypeAttributeRepository;
use App\Repository\Sde\DgmTypeEffectRepository;
use App\Repository\Sde\InvTypeRepository;

trait StubsSdeStructureBonuses
{
    private const string SDE_AMMUNITION_ME_II = 'Standup M-Set Ammunition Manufacturing Material Efficiency II';
    private const string SDE_EQUIPMENT_TE_I = 'Standup M-Set Equipment Manufacturing Time Efficiency I';
    private const string SDE_COMPOSITE_REACTOR_ME_II = 'Standup M-Set Composite Reactor Material Efficiency II';
    private const string SDE_COMPOSITE_REACTOR_TE_I = 'Standup M-Set Composite Reactor Time Efficiency I';
    private const string SDE_M_SET_THUKKER_BASIC_CAPITAL_COMPONENT = 'Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency';
    private const string SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT = 'Standup XL-Set Thukker Structure and Component Manufacturing Efficiency';
    private const string SDE_M_SET_THUKKER_ADVANCED_COMPONENT = 'Standup M-Set Thukker Advanced Component Manufacturing Material Efficiency';
    private const string SDE_XL_SET_LABORATORY_OPTIMIZATION_II = 'Standup XL-Set Laboratory Optimization II';
    private const string SDE_M_SET_BLUEPRINT_COPY_ACCELERATOR_II = 'Standup M-Set Blueprint Copy Accelerator II';
    private const string SDE_L_SET_REACTOR_EFFICIENCY_II = 'Standup L-Set Reactor Efficiency II';
    private const string SDE_L_SET_BASIC_LARGE_SHIP_EFFICIENCY_II = 'Standup L-Set Basic Large Ship Manufacturing Efficiency II';
    private const string SDE_M_SET_BASIC_LARGE_SHIP_ME_I = 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency I';
    private const string SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_II = 'Standup M-Set Laboratory Optimization II';
    private const string SDE_REMOVED_M_SET_LABORATORY_OPTIMIZATION_I = 'Standup M-Set Laboratory Optimization I';

    private const array SDE_RIG_TYPE_IDS = [
        self::SDE_AMMUNITION_ME_II => 37159,
        self::SDE_EQUIPMENT_TE_I => 37160,
        self::SDE_COMPOSITE_REACTOR_ME_II => 46487,
        self::SDE_COMPOSITE_REACTOR_TE_I => 46484,
        self::SDE_M_SET_THUKKER_BASIC_CAPITAL_COMPONENT => 45544,
        self::SDE_XL_SET_THUKKER_STRUCTURE_AND_COMPONENT => 45548,
        self::SDE_M_SET_THUKKER_ADVANCED_COMPONENT => 45640,
        self::SDE_XL_SET_LABORATORY_OPTIMIZATION_II => 37182,
        self::SDE_M_SET_BLUEPRINT_COPY_ACCELERATOR_II => 43892,
        self::SDE_L_SET_REACTOR_EFFICIENCY_II => 46497,
        self::SDE_L_SET_BASIC_LARGE_SHIP_EFFICIENCY_II => 999_000_201,
        self::SDE_M_SET_BASIC_LARGE_SHIP_ME_I => 999_000_202,
    ];

    private const array SDE_DOGMA_ATTRIBUTES = [
        35825 => [2600 => 0.99, 2601 => 0.97, 2602 => 0.85],
        35826 => [2600 => 0.99, 2601 => 0.96, 2602 => 0.8],
        35827 => [2600 => 0.99, 2601 => 0.95, 2602 => 0.7],
        35835 => [],
        35836 => [2721 => 0.75],
        37159 => [2355 => 1, 2356 => 1.9, 2357 => 2.1, 2593 => 0, 2594 => -2.4, 2595 => 0],
        37160 => [2355 => 1, 2356 => 1.9, 2357 => 2.1, 2593 => -20, 2594 => 0, 2595 => 0],
        46487 => [2356 => 1, 2357 => 1.1, 2714 => -2.4],
        46484 => [2356 => 1, 2357 => 1.1, 2713 => -20],
        45544 => [2355 => 0.1, 2356 => 1.9, 2357 => 0.1, 2593 => 0, 2653 => -3.7],
        45640 => [2355 => 0.1, 2356 => 1.9, 2357 => 0.1, 2593 => 0, 2594 => -2, 2653 => -3.7],
        45548 => [2355 => 0.1, 2356 => 1.9, 2357 => 0.1, 2593 => -20, 2594 => -2, 2653 => -3.7],
        37182 => [2355 => 1, 2356 => 1.9, 2357 => 2.1, 2593 => -24, 2594 => 0, 2595 => -12],
        43892 => [2355 => 1, 2356 => 1.9, 2357 => 2.1, 2593 => -24, 2594 => 0, 2595 => 0],
        46497 => [2356 => 1, 2357 => 1.1, 2713 => -24, 2714 => -2.4],
        999_000_201 => [2355 => 1, 2356 => 1.9, 2357 => 2.1, 2593 => -24, 2594 => -2.4, 2595 => 0],
        999_000_202 => [2355 => 1, 2356 => 1.9, 2357 => 2.1, 2593 => 0, 2594 => -2, 2595 => 0],
    ];

    private const array SDE_DOGMA_EFFECTS = [
        37182 => [2663, 6830, 6831, 6832, 6833, 6834, 6835, 6836, 6837, 6842],
        43892 => [2663, 6837, 6842],
    ];

    private function sdeStructureBonusSummary(): StructureBonusSummary
    {
        return new StructureBonusSummary($this->sdeRigTypes(), $this->sdeStructureBonusReader(), $this->sdeRigBonusReader());
    }

    private function sdeStructureBonusReader(): SdeStructureBonusReader
    {
        return new SdeStructureBonusReader($this->sdeDogmaAttributeValues());
    }

    private function sdeRigBonusReader(): SdeRigBonusReader
    {
        return new SdeRigBonusReader($this->sdeDogmaAttributeValues(), $this->sdeDogmaEffects());
    }

    private function sdeRigTypes(): InvTypeRepository
    {
        $repository = $this->createStub(InvTypeRepository::class);
        $repository->method('findByNames')->willReturnCallback(static fn (array $names): array => self::sdeRigTypesNamed($names));

        return $repository;
    }

    private static function sdeRigTypesNamed(array $names): array
    {
        $resolved = [];
        foreach ($names as $name) {
            if (\array_key_exists($name, self::SDE_RIG_TYPE_IDS)) {
                $resolved[$name] = (new InvType())
                    ->setTypeId(self::SDE_RIG_TYPE_IDS[$name])
                    ->setTypeName($name)
                    ->setGroup(new InvGroup());
            }
        }

        return $resolved;
    }

    private function sdeDogmaAttributeValues(): DogmaAttributeValues
    {
        $repository = $this->createStub(DgmTypeAttributeRepository::class);
        $repository->method('findBy')->willReturnCallback(static function (array $criteria): array {
            $rows = [];
            foreach (self::SDE_DOGMA_ATTRIBUTES[$criteria['typeId']] ?? [] as $attributeId => $value) {
                if (!\in_array($attributeId, (array) $criteria['attributeId'], true)) {
                    continue;
                }
                $row = (new DgmTypeAttribute())->setTypeId($criteria['typeId'])->setAttributeId($attributeId);
                $rows[] = \is_int($value) ? $row->setValueInt($value) : $row->setValueFloat($value);
            }

            return $rows;
        });

        return new DogmaAttributeValues($repository);
    }

    private function sdeDogmaEffects(): DgmTypeEffectRepository
    {
        $repository = $this->createStub(DgmTypeEffectRepository::class);
        $repository->method('findByTypeId')->willReturnCallback(static fn (int $typeId): array => array_map(
            static fn (int $effectId): DgmTypeEffect => (new DgmTypeEffect())->setTypeId($typeId)->setEffectId($effectId)->setIsDefault(false),
            self::SDE_DOGMA_EFFECTS[$typeId] ?? [],
        ));

        return $repository;
    }

    private static function sdeBonusesAsList(StructureBonuses $bonuses): array
    {
        return [
            $bonuses->manufacturingMaterialBonus,
            $bonuses->reactionMaterialBonus,
            $bonuses->manufacturingTimeBonus,
            $bonuses->reactionTimeBonus,
            $bonuses->unrecognisedRigs,
        ];
    }
}
