<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Industry;

use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Industry\IndustryBonusService;

final class TataraAndRaitaruBonusService extends IndustryBonusService
{
    private const array FUEL_BLOCKS = [4246, 4051, 4312, 4247];
    private const int QUANTUM_MICROPROCESSOR = 11540;
    private const string FUEL_BLOCK_CATEGORY = 'ammunition';
    private const string ADVANCED_COMPONENT_CATEGORY = 'advanced_component';
    private const string REACTION_CATEGORY = 'composite_reaction';
    private const float RAITARU_BASE_MATERIAL_BONUS = 1.0;
    private const float TATARA_REACTOR_RIG_BONUS = 2.64;

    public function __construct(
        private readonly \Closure $pilotOwnsTataraAndRaitaru,
        private readonly \Closure $isReactionProduct,
        IndustryRigCategoryRepository $categoryRepository,
        IndustryStructureConfigRepository $structureRepository,
        InvTypeRepository $invTypeRepository,
        IndustryActivityProductRepository $activityProductRepository,
    ) {
        parent::__construct($categoryRepository, $structureRepository, $invTypeRepository, $activityProductRepository);
    }

    public function getCategoryForProduct(int $typeId, bool $isReaction = false): ?string
    {
        return match (true) {
            !($this->pilotOwnsTataraAndRaitaru)() => null,
            $isReaction && ($this->isReactionProduct)($typeId) => self::REACTION_CATEGORY,
            \in_array($typeId, self::FUEL_BLOCKS, true) => self::FUEL_BLOCK_CATEGORY,
            $typeId === self::QUANTUM_MICROPROCESSOR => self::ADVANCED_COMPONENT_CATEGORY,
            default => null,
        };
    }

    public function findBestStructureForProduct(User $user, int $typeId, bool $isReaction = false): array
    {
        if (!($this->pilotOwnsTataraAndRaitaru)()) {
            return self::noStructure();
        }

        $category = $this->getCategoryForProduct($typeId, $isReaction);

        return $isReaction ? self::tatara($category) : self::raitaru($category);
    }

    public function findBestStructureForCategory(User $user, string $category, bool $isReaction = false): array
    {
        return $isReaction ? self::tatara($category) : self::raitaru($category);
    }

    private static function tatara(?string $category): array
    {
        $tatara = (new IndustryStructureConfig())->setName('Tatara')->setStructureType('tatara')->setSecurityType('nullsec')
            ->setRigs(['Standup L-Set Reactor Efficiency II']);
        $rigBonus = $category === self::REACTION_CATEGORY ? self::TATARA_REACTOR_RIG_BONUS : 0.0;

        return ['structure' => $tatara, 'bonus' => ['total' => $rigBonus, 'base' => 0.0, 'rig' => $rigBonus], 'category' => $category];
    }

    private static function raitaru(?string $category): array
    {
        $raitaru = (new IndustryStructureConfig())->setName('Raitaru')->setStructureType('raitaru')->setSecurityType('nullsec');

        return ['structure' => $raitaru, 'bonus' => ['total' => self::RAITARU_BASE_MATERIAL_BONUS, 'base' => self::RAITARU_BASE_MATERIAL_BONUS, 'rig' => 0.0], 'category' => $category];
    }

    private static function noStructure(): array
    {
        return ['structure' => null, 'bonus' => ['total' => 0.0, 'base' => 0.0, 'rig' => 0.0], 'category' => null];
    }
}
