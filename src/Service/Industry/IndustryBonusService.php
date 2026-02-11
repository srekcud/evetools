<?php

declare(strict_types=1);

namespace App\Service\Industry;

use App\Entity\IndustryStructureConfig;
use App\Entity\User;
use App\Repository\IndustryRigCategoryRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\IndustryActivityProductRepository;
use App\Repository\Sde\InvTypeRepository;
use Psr\Log\LoggerInterface;

/**
 * Service for calculating industry structure bonuses.
 * Determines the best structure for a given product based on user's configured structures.
 */
class IndustryBonusService
{
    /** @var array<int, string>|null Cached group -> category map */
    private ?array $groupCategoryMap = null;

    /** @var array<string, array<string, float>> Rig name -> [category => bonus] mapping */
    private array $rigBonusMap = [];

    /** @var array<string, array<string, float>> Rig name -> [category => time bonus] mapping */
    private array $rigTimeBonusMap = [];

    private const ACTIVITY_REACTION = 11;

    // Structure base time bonuses (fixed per structure type)
    private const STRUCTURE_TIME_BONUSES = [
        'raitaru' => 15.0,
        'azbel' => 20.0,
        'sotiyo' => 30.0,
        'athanor' => 25.0,
        'tatara' => 25.0,
        'station' => 0.0,
        // Legacy support
        'engineering_complex' => 20.0,
        'refinery' => 25.0,
    ];

    // Structure base material bonuses (1% ME for Engineering Complexes)
    private const STRUCTURE_MATERIAL_BONUSES = [
        'raitaru' => 1.0,
        'azbel' => 1.0,
        'sotiyo' => 1.0,
        'athanor' => 0.0,
        'tatara' => 0.0,
        'station' => 0.0,
        // Legacy support
        'engineering_complex' => 1.0,
        'refinery' => 0.0,
    ];

    // Mapping structure types to their category (EC vs Refinery)
    private const STRUCTURE_CATEGORIES = [
        'raitaru' => 'engineering_complex',
        'azbel' => 'engineering_complex',
        'sotiyo' => 'engineering_complex',
        'athanor' => 'refinery',
        'tatara' => 'refinery',
        'station' => 'station',
        'engineering_complex' => 'engineering_complex',
        'refinery' => 'refinery',
    ];

    // Default TE for intermediate blueprints (like ME 10)
    private const DEFAULT_INTERMEDIATE_TE = 20;

    public function __construct(
        private readonly IndustryRigCategoryRepository $categoryRepository,
        private readonly IndustryStructureConfigRepository $structureRepository,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly IndustryActivityProductRepository $activityProductRepository,
        private readonly LoggerInterface $logger,
    ) {
        $this->initializeRigBonusMap();
        $this->initializeRigTimeBonusMap();
    }

    /**
     * Get the industry category for a product type.
     * For reactions, uses the formula's group (not the product's group).
     */
    public function getCategoryForProduct(int $typeId, bool $isReaction = false): ?string
    {
        // For reactions, we need to check the FORMULA's group, not the product's group
        if ($isReaction) {
            $formula = $this->activityProductRepository->findBlueprintForProduct($typeId, self::ACTIVITY_REACTION);
            if ($formula) {
                $formulaType = $this->invTypeRepository->find($formula->getTypeId());
                if ($formulaType) {
                    $groupId = $formulaType->getGroup()->getGroupId();
                    return $this->getCategoryForGroup($groupId);
                }
            }
        }

        // For manufacturing, use the product's group
        $type = $this->invTypeRepository->find($typeId);
        if (!$type) {
            return null;
        }

        $group = $type->getGroup();
        $groupId = $group->getGroupId();

        return $this->getCategoryForGroup($groupId);
    }

    /**
     * Get the industry category for a group ID.
     */
    public function getCategoryForGroup(int $groupId): ?string
    {
        if ($this->groupCategoryMap === null) {
            $this->groupCategoryMap = $this->categoryRepository->buildGroupCategoryMap();
        }

        return $this->groupCategoryMap[$groupId] ?? null;
    }

    /**
     * Find the best structure and its bonus for a given product.
     *
     * @return array{structure: IndustryStructureConfig|null, bonus: float, category: string|null}
     */
    public function findBestStructureForProduct(User $user, int $typeId, bool $isReaction = false): array
    {
        $category = $this->getCategoryForProduct($typeId, $isReaction);

        if (!$category) {
            // No rig category for this product, but base structure bonuses still apply.
            // Find the best structure based on base bonus only.
            return $this->findBestStructureBaseOnly($user, $isReaction);
        }

        return $this->findBestStructureForCategory($user, $category, $isReaction);
    }

    /**
     * Find the best structure when no rig category matches (e.g. Deployables).
     * Only base structure bonuses are considered.
     *
     * @return array{structure: IndustryStructureConfig|null, bonus: float, category: string|null}
     */
    private function findBestStructureBaseOnly(User $user, bool $isReaction): array
    {
        $structures = $this->structureRepository->findByUser($user);

        if (empty($structures)) {
            return ['structure' => null, 'bonus' => 0.0, 'category' => null];
        }

        $bestStructure = null;
        $bestBaseTime = 0.0;

        foreach ($structures as $structure) {
            $structureType = $structure->getStructureType();
            $structureCategory = self::STRUCTURE_CATEGORIES[$structureType] ?? $structureType;

            if ($isReaction && $structureCategory !== 'refinery') {
                continue;
            }
            if (!$isReaction && $structureCategory === 'refinery') {
                continue;
            }

            $baseTime = self::STRUCTURE_TIME_BONUSES[$structureType] ?? 0.0;
            if ($baseTime > $bestBaseTime) {
                $bestBaseTime = $baseTime;
                $bestStructure = $structure;
            }
        }

        $materialBonus = 0.0;
        if ($bestStructure !== null && !$isReaction) {
            $materialBonus = self::STRUCTURE_MATERIAL_BONUSES[$bestStructure->getStructureType()] ?? 0.0;
        }

        return ['structure' => $bestStructure, 'bonus' => $materialBonus, 'category' => null];
    }

    /**
     * Get the base material bonus for a structure (without rigs).
     * Engineering Complexes: 1% ME for manufacturing. Refineries: 0%.
     */
    public function getBaseMaterialBonus(IndustryStructureConfig $structure, bool $isReaction): float
    {
        if ($isReaction) {
            return 0.0;
        }

        $structureType = $structure->getStructureType();
        $structureCategory = self::STRUCTURE_CATEGORIES[$structureType] ?? $structureType;

        if ($structureCategory === 'engineering_complex') {
            return self::STRUCTURE_MATERIAL_BONUSES[$structureType] ?? 0.0;
        }

        return 0.0;
    }

    /**
     * Get the base time bonus for a structure (without rigs).
     * This applies to ALL manufacturing/reactions in the structure, regardless of product category.
     */
    public function getBaseTimeBonus(IndustryStructureConfig $structure, bool $isReaction): float
    {
        $structureType = $structure->getStructureType();
        $structureCategory = self::STRUCTURE_CATEGORIES[$structureType] ?? $structureType;

        if (!$isReaction && $structureCategory === 'engineering_complex') {
            return self::STRUCTURE_TIME_BONUSES[$structureType] ?? 0.0;
        }
        if ($isReaction && $structureCategory === 'refinery') {
            return self::STRUCTURE_TIME_BONUSES[$structureType] ?? 0.0;
        }

        return 0.0;
    }

    /**
     * Find the best structure and its bonus for a given category.
     *
     * @return array{structure: IndustryStructureConfig|null, bonus: float, category: string|null}
     */
    public function findBestStructureForCategory(User $user, string $category, bool $isReaction = false): array
    {
        $structures = $this->structureRepository->findByUser($user);

        if (empty($structures)) {
            return ['structure' => null, 'bonus' => 0.0, 'category' => $category];
        }

        $bestStructure = null;
        $bestBonus = 0.0;

        foreach ($structures as $structure) {
            $structureType = $structure->getStructureType();
            $structureCategory = self::STRUCTURE_CATEGORIES[$structureType] ?? $structureType;

            // Skip refineries for manufacturing, skip engineering complexes for reactions
            if ($isReaction && $structureCategory !== 'refinery') {
                continue;
            }
            if (!$isReaction && $structureCategory === 'refinery') {
                continue;
            }

            $bonus = $this->calculateStructureBonusForCategory($structure, $category);

            if ($bonus > $bestBonus) {
                $bestBonus = $bonus;
                $bestStructure = $structure;
            }
        }

        return ['structure' => $bestStructure, 'bonus' => $bestBonus, 'category' => $category];
    }

    /**
     * Calculate the bonus a structure provides for a specific category.
     * Includes base structure bonuses:
     * - Engineering Complexes (Raitaru, Azbel, Sotiyo): 1% material reduction for manufacturing
     * - Refineries (Athanor, Tatara): NO material bonus (only time bonus)
     * - NPC Stations: NO bonuses
     */
    public function calculateStructureBonusForCategory(IndustryStructureConfig $structure, string $category): float
    {
        $isReactionCategory = str_contains($category, 'reaction');
        $structureType = $structure->getStructureType();
        $structureCategory = self::STRUCTURE_CATEGORIES[$structureType] ?? $structureType;

        // Base structure bonus (from lookup table, only for manufacturing)
        $baseBonus = 0.0;
        if (!$isReactionCategory) {
            $baseBonus = self::STRUCTURE_MATERIAL_BONUSES[$structureType] ?? 0.0;
        }

        $rigBonus = 0.0;
        $securityMultiplier = $this->getSecurityMultiplier($structure->getSecurityType(), $isReactionCategory);

        foreach ($structure->getRigs() as $rigName) {
            if (!isset($this->rigBonusMap[$rigName])) {
                continue;
            }

            $rigBonuses = $this->rigBonusMap[$rigName];

            if (isset($rigBonuses[$category])) {
                $rigBonus += $rigBonuses[$category];
            }
        }

        // Total bonus = base structure bonus + (rig bonus × security multiplier)
        return round($baseBonus + ($rigBonus * $securityMultiplier), 2);
    }

    /**
     * Get all bonuses a structure provides, grouped by category.
     * Includes base structure bonuses (1% for EC manufacturing).
     *
     * @return array<string, float> category => bonus
     */
    public function calculateAllBonusesForStructure(IndustryStructureConfig $structure): array
    {
        $bonusesByCategory = [];
        $structureType = $structure->getStructureType();

        foreach ($structure->getRigs() as $rigName) {
            if (!isset($this->rigBonusMap[$rigName])) {
                continue;
            }

            foreach ($this->rigBonusMap[$rigName] as $category => $bonus) {
                if (!isset($bonusesByCategory[$category])) {
                    $bonusesByCategory[$category] = 0.0;
                }
                $bonusesByCategory[$category] += $bonus;
            }
        }

        // Apply security multiplier (different for reactions vs manufacturing) and add base structure bonus
        foreach ($bonusesByCategory as $category => $bonus) {
            $isReactionCategory = str_contains($category, 'reaction');
            $securityMultiplier = $this->getSecurityMultiplier($structure->getSecurityType(), $isReactionCategory);

            // Base structure bonus (from lookup table, only for manufacturing)
            $baseBonus = 0.0;
            if (!$isReactionCategory) {
                $baseBonus = self::STRUCTURE_MATERIAL_BONUSES[$structureType] ?? 0.0;
            }

            $bonusesByCategory[$category] = round($baseBonus + ($bonus * $securityMultiplier), 2);
        }

        return $bonusesByCategory;
    }

    /**
     * Get the security multiplier for rig bonuses.
     * Manufacturing rigs and Reaction rigs have different multipliers.
     *
     * Manufacturing rigs: highsec 1.0, lowsec 1.9, nullsec 2.1
     * Reaction rigs: highsec 1.0, lowsec 1.0, nullsec 1.1
     */
    private function getSecurityMultiplier(string $securityType, bool $isReaction = false): float
    {
        if ($isReaction) {
            // Reactor rigs have lower multipliers than manufacturing rigs
            return match ($securityType) {
                'highsec' => 1.0,
                'lowsec' => 1.0,
                'nullsec' => 1.1,
                default => 1.0,
            };
        }

        // Manufacturing rigs
        return match ($securityType) {
            'highsec' => 1.0,
            'lowsec' => 1.9,
            'nullsec' => 2.1,
            default => 1.0,
        };
    }

    /**
     * Find the best structure and its TIME bonus for a given product.
     *
     * @return array{structure: IndustryStructureConfig|null, bonus: float, category: string|null}
     */
    public function findBestStructureForProductTimeBonus(User $user, int $typeId, bool $isReaction = false): array
    {
        $category = $this->getCategoryForProduct($typeId, $isReaction);

        if (!$category) {
            return ['structure' => null, 'bonus' => 0.0, 'category' => null];
        }

        return $this->findBestStructureForCategoryTimeBonus($user, $category, $isReaction);
    }

    /**
     * Find the best structure and its TIME bonus for a given category.
     *
     * @return array{structure: IndustryStructureConfig|null, bonus: float, category: string|null}
     */
    public function findBestStructureForCategoryTimeBonus(User $user, string $category, bool $isReaction = false): array
    {
        $structures = $this->structureRepository->findByUser($user);

        if (empty($structures)) {
            return ['structure' => null, 'bonus' => 0.0, 'category' => $category];
        }

        $bestStructure = null;
        $bestBonus = 0.0;

        foreach ($structures as $structure) {
            $structureType = $structure->getStructureType();
            $structureCategory = self::STRUCTURE_CATEGORIES[$structureType] ?? $structureType;

            // Skip refineries for manufacturing, skip engineering complexes for reactions
            if ($isReaction && $structureCategory !== 'refinery') {
                continue;
            }
            if (!$isReaction && $structureCategory === 'refinery') {
                continue;
            }

            $bonus = $this->calculateStructureTimeBonusForCategory($structure, $category);

            if ($bonus > $bestBonus) {
                $bestBonus = $bonus;
                $bestStructure = $structure;
            }
        }

        return ['structure' => $bestStructure, 'bonus' => $bestBonus, 'category' => $category];
    }

    /**
     * Calculate the TIME bonus a structure provides for a specific category.
     * Includes base structure time bonuses:
     * - Raitaru: 15%, Azbel: 20%, Sotiyo: 30% for manufacturing
     * - Athanor/Tatara: 25% for reactions
     *
     * Time bonuses are multiplicative, not additive:
     * totalReduction = 1 - (1 - baseBonus/100) × (1 - rigBonus/100)
     */
    public function calculateStructureTimeBonusForCategory(IndustryStructureConfig $structure, string $category): float
    {
        $isReactionCategory = str_contains($category, 'reaction');
        $structureType = $structure->getStructureType();
        $structureCategory = self::STRUCTURE_CATEGORIES[$structureType] ?? $structureType;

        // Base structure time bonus (from lookup table)
        $baseBonus = 0.0;
        if (!$isReactionCategory && $structureCategory === 'engineering_complex') {
            $baseBonus = self::STRUCTURE_TIME_BONUSES[$structureType] ?? 0.0;
        } elseif ($isReactionCategory && $structureCategory === 'refinery') {
            $baseBonus = self::STRUCTURE_TIME_BONUSES[$structureType] ?? 0.0;
        }

        $rigBonus = 0.0;
        $securityMultiplier = $this->getSecurityMultiplier($structure->getSecurityType(), $isReactionCategory);

        foreach ($structure->getRigs() as $rigName) {
            if (!isset($this->rigTimeBonusMap[$rigName])) {
                continue;
            }

            $rigBonuses = $this->rigTimeBonusMap[$rigName];

            if (isset($rigBonuses[$category])) {
                $rigBonus += $rigBonuses[$category];
            }
        }

        // Apply security multiplier to rig bonus
        $rigBonus *= $securityMultiplier;

        // Time bonuses stack multiplicatively
        // totalReduction = 1 - (1 - base) × (1 - rig)
        $totalBonus = 1 - (1 - $baseBonus / 100) * (1 - $rigBonus / 100);

        return round($totalBonus * 100, 2);
    }

    /**
     * Calculate adjusted time per run with TE and structure bonuses.
     *
     * @param int $baseTimePerRun Base time per run in seconds (from SDE)
     * @param int $teLevel Blueprint Time Efficiency level (0-20)
     * @param float $structureTimeBonus Structure time bonus percentage (0-50)
     * @return int Adjusted time per run in seconds
     */
    public function calculateAdjustedTimePerRun(int $baseTimePerRun, int $teLevel = 0, float $structureTimeBonus = 0.0): int
    {
        // Formula: baseTime × (1 - TE/100) × (1 - structureBonus/100)
        $teMultiplier = 1 - $teLevel / 100;
        $structureMultiplier = 1 - $structureTimeBonus / 100;

        return (int) ceil($baseTimePerRun * $teMultiplier * $structureMultiplier);
    }

    /**
     * Get the default TE level for intermediate blueprints.
     */
    public function getDefaultIntermediateTE(): int
    {
        return self::DEFAULT_INTERMEDIATE_TE;
    }

    /**
     * Initialize the rig -> category bonus mapping from the rig options.
     * This maps each rig name to the categories it affects and its base bonus.
     */
    private function initializeRigBonusMap(): void
    {
        $rigOptions = $this->getRigOptions();

        foreach (['manufacturing', 'reaction'] as $type) {
            if (!isset($rigOptions[$type])) {
                continue;
            }

            foreach ($rigOptions[$type] as $rig) {
                $rigName = $rig['name'];
                $baseBonus = $rig['bonus'];
                $targetCategories = $rig['targetCategories'] ?? [];

                if (!isset($this->rigBonusMap[$rigName])) {
                    $this->rigBonusMap[$rigName] = [];
                }

                foreach ($targetCategories as $category) {
                    $this->rigBonusMap[$rigName][$category] = $baseBonus;
                }
            }
        }
    }

    /**
     * Initialize the rig -> category TIME bonus mapping.
     * - L-Set and XL-Set "Efficiency" rigs provide BOTH material AND time bonuses.
     * - M-Set "Material Efficiency" rigs only provide material bonus (no time).
     * - M-Set "Time Efficiency" rigs only provide time bonus (no material).
     *
     * IMPORTANT: For L-Set/XL-Set "Efficiency" rigs, time bonuses are 10× the material bonuses in EVE Online!
     * - L-Set Reactor Efficiency II: 2.4% ME, but 24% TE
     * - XL-Set Ship Manufacturing Efficiency I: 2.0% ME, but 20% TE
     *
     * M-Set "Time Efficiency" rigs have their timeBonus specified directly in the rig definition.
     */
    private function initializeRigTimeBonusMap(): void
    {
        $rigOptions = $this->getRigOptions();

        foreach (['manufacturing', 'reaction'] as $type) {
            if (!isset($rigOptions[$type])) {
                continue;
            }

            foreach ($rigOptions[$type] as $rig) {
                $rigName = $rig['name'];
                $materialBonus = $rig['bonus'];
                $targetCategories = $rig['targetCategories'] ?? [];
                $explicitTimeBonus = $rig['timeBonus'] ?? null;

                // M-Set "Time Efficiency" rigs have explicit timeBonus
                if ($explicitTimeBonus !== null) {
                    if (!isset($this->rigTimeBonusMap[$rigName])) {
                        $this->rigTimeBonusMap[$rigName] = [];
                    }
                    foreach ($targetCategories as $category) {
                        $this->rigTimeBonusMap[$rigName][$category] = $explicitTimeBonus;
                    }
                    continue;
                }

                // Only L-Set, XL-Set, and Reactor "Efficiency" rigs have time bonuses
                // M-Set "Material Efficiency" rigs do NOT have time bonuses
                // Check if this is an "Efficiency" rig (not "Material Efficiency")
                $hasTimeBonus = false;

                // L-Set and XL-Set Manufacturing Efficiency rigs (not "Material Efficiency")
                if (
                    (str_contains($rigName, 'L-Set') || str_contains($rigName, 'XL-Set'))
                    && str_contains($rigName, 'Efficiency')
                    && !str_contains($rigName, 'Material Efficiency')
                    && !str_contains($rigName, 'Time Efficiency')
                ) {
                    $hasTimeBonus = true;
                }

                // Reactor Efficiency rigs (L-Set only in current EVE)
                if (str_contains($rigName, 'Reactor Efficiency')) {
                    $hasTimeBonus = true;
                }

                // Thukker versions also have time bonuses
                if (str_contains($rigName, 'Thukker') && !str_contains($rigName, 'Material Efficiency')) {
                    $hasTimeBonus = true;
                }

                if (!$hasTimeBonus) {
                    continue;
                }

                if (!isset($this->rigTimeBonusMap[$rigName])) {
                    $this->rigTimeBonusMap[$rigName] = [];
                }

                // Time bonus is 10× the material bonus in EVE Online
                // E.g., 2.4% ME → 24% TE for L-Set Reactor Efficiency II
                $timeBonus = $materialBonus * 10.0;

                foreach ($targetCategories as $category) {
                    $this->rigTimeBonusMap[$rigName][$category] = $timeBonus;
                }
            }
        }
    }

    /**
     * Get the rig options (same as IndustryController).
     * @return array<string, array<array<string, mixed>>>
     */
    private function getRigOptions(): array
    {
        return [
            'manufacturing' => [
                // M-Set Ships - Material Efficiency
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['advanced_large_ship']],
                // M-Set Ships - Time Efficiency
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['advanced_large_ship']],
                // M-Set Components - Material Efficiency
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency', 'bonus' => 2.4, 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Thukker Advanced Component Manufacturing Material Efficiency', 'bonus' => 2.4, 'targetCategories' => ['advanced_component']],
                // M-Set Components - Time Efficiency
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['advanced_component']],
                // M-Set Equipment - Material Efficiency
                ['name' => 'Standup M-Set Equipment Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Equipment Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['drone', 'fighter']],
                // M-Set Equipment - Time Efficiency
                ['name' => 'Standup M-Set Equipment Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Equipment Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['drone', 'fighter']],
                // M-Set Structures - Material Efficiency
                ['name' => 'Standup M-Set Structure Manufacturing Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup M-Set Structure Manufacturing Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['structure', 'structure_component']],
                // M-Set Structures - Time Efficiency
                ['name' => 'Standup M-Set Structure Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup M-Set Structure Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'targetCategories' => ['structure', 'structure_component']],

                // L-Set Ships
                ['name' => 'Standup L-Set Basic Small Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup L-Set Basic Small Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup L-Set Basic Medium Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup L-Set Basic Medium Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup L-Set Basic Large Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup L-Set Basic Large Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup L-Set Advanced Small Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup L-Set Advanced Small Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup L-Set Advanced Medium Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup L-Set Advanced Medium Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup L-Set Advanced Large Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup L-Set Advanced Large Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup L-Set Capital Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['capital_ship']],
                ['name' => 'Standup L-Set Capital Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['capital_ship']],
                // L-Set Components
                ['name' => 'Standup L-Set Basic Capital Component Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Basic Capital Component Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Advanced Component Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup L-Set Advanced Component Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup L-Set Thukker Basic Capital Component Manufacturing Efficiency', 'bonus' => 2.4, 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Thukker Advanced Component Manufacturing Efficiency', 'bonus' => 2.4, 'targetCategories' => ['advanced_component']],
                // L-Set Equipment
                ['name' => 'Standup L-Set Equipment Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['equipment']],
                ['name' => 'Standup L-Set Equipment Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['equipment']],
                ['name' => 'Standup L-Set Ammunition Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['ammunition']],
                ['name' => 'Standup L-Set Ammunition Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['ammunition']],
                ['name' => 'Standup L-Set Drone and Fighter Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup L-Set Drone and Fighter Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['drone', 'fighter']],
                // L-Set Structures
                ['name' => 'Standup L-Set Structure Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup L-Set Structure Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['structure', 'structure_component']],

                // XL-Set
                ['name' => 'Standup XL-Set Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship']],
                ['name' => 'Standup XL-Set Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship']],
                ['name' => 'Standup XL-Set Equipment and Consumable Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['equipment', 'ammunition', 'drone', 'fighter']],
                ['name' => 'Standup XL-Set Equipment and Consumable Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['equipment', 'ammunition', 'drone', 'fighter']],
                ['name' => 'Standup XL-Set Structure and Component Manufacturing Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],
                ['name' => 'Standup XL-Set Structure and Component Manufacturing Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],
                ['name' => 'Standup XL-Set Thukker Structure and Component Manufacturing Efficiency', 'bonus' => 2.4, 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],
            ],
            'reaction' => [
                // M-Set Reactions
                ['name' => 'Standup M-Set Composite Reactor Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['composite_reaction']],
                ['name' => 'Standup M-Set Composite Reactor Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['composite_reaction']],
                ['name' => 'Standup M-Set Biochemical Reactor Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['biochemical_reaction']],
                ['name' => 'Standup M-Set Biochemical Reactor Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['biochemical_reaction']],
                ['name' => 'Standup M-Set Hybrid Reactor Material Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['hybrid_reaction']],
                ['name' => 'Standup M-Set Hybrid Reactor Material Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['hybrid_reaction']],
                // L-Set Reactions
                ['name' => 'Standup L-Set Reactor Efficiency I', 'bonus' => 2.0, 'targetCategories' => ['composite_reaction', 'biochemical_reaction', 'hybrid_reaction']],
                ['name' => 'Standup L-Set Reactor Efficiency II', 'bonus' => 2.4, 'targetCategories' => ['composite_reaction', 'biochemical_reaction', 'hybrid_reaction']],
            ],
        ];
    }
}
