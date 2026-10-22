<?php

declare(strict_types=1);

namespace App\Constant;

final class StructureRigTargets
{
    /** @var array<string, list<string>> */
    private const array CATEGORIES_BY_NAME_PATTERN = [
        'Advanced Large Ship Manufacturing' => ['advanced_large_ship'],
        'Advanced Medium Ship Manufacturing' => ['advanced_medium_ship'],
        'Advanced Small Ship Manufacturing' => ['advanced_small_ship'],
        'Basic Large Ship Manufacturing' => ['basic_large_ship'],
        'Basic Medium Ship Manufacturing' => ['basic_medium_ship'],
        'Basic Small Ship Manufacturing' => ['basic_small_ship'],
        'Capital Ship Manufacturing' => ['capital_ship'],
        'Basic Capital Component Manufacturing' => ['basic_capital_component'],
        'Advanced Component Manufacturing' => ['advanced_component'],
        'Structure and Component Manufacturing' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component'],
        'Ship Manufacturing Efficiency' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship'],
        'Structure Manufacturing' => ['structure', 'structure_component'],
        'Equipment and Consumable Manufacturing' => ['equipment', 'ammunition', 'drone', 'fighter'],
        'Equipment Manufacturing' => ['equipment'],
        'Ammunition Manufacturing' => ['ammunition'],
        'Drone and Fighter Manufacturing' => ['drone', 'fighter'],
        'Composite Reactor Material' => ['composite_reaction'],
        'Composite Reactor Time' => ['composite_reaction'],
        'Hybrid Reactor Material' => ['hybrid_reaction'],
        'Hybrid Reactor Time' => ['hybrid_reaction'],
        'Biochemical Reactor Material' => ['biochemical_reaction'],
        'Biochemical Reactor Time' => ['biochemical_reaction'],
        'Reactor Efficiency' => ['composite_reaction', 'biochemical_reaction', 'hybrid_reaction'],
    ];

    /**
     * @return list<string>
     */
    public static function categoriesOf(string $rigName): array
    {
        foreach (self::CATEGORIES_BY_NAME_PATTERN as $pattern => $categories) {
            if (str_contains($rigName, $pattern)) {
                return $categories;
            }
        }

        return [];
    }
}
