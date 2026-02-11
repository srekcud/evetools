<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\RigOptionsResource;

/**
 * @implements ProviderInterface<RigOptionsResource>
 */
class RigOptionsProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): RigOptionsResource
    {
        $options = $this->getRigOptionsArray();

        $resource = new RigOptionsResource();
        $resource->manufacturing = $options['manufacturing'];
        $resource->reaction = $options['reaction'];

        return $resource;
    }

    public function getRigOptionsArray(): array
    {
        return [
            'manufacturing' => [
                // === M-Set (Medium: Raitaru) ===

                // Ships ME
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ships ME', 'size' => 'M', 'targetCategories' => ['advanced_large_ship']],
                // Ships TE
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Small Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Medium Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Basic Large Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Small Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Medium Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup M-Set Advanced Large Ship Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Ships TE', 'size' => 'M', 'targetCategories' => ['advanced_large_ship']],

                // Components ME
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Thukker Basic Capital Component Manufacturing Material Efficiency', 'bonus' => 2.4, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Thukker Advanced Component Manufacturing Material Efficiency', 'bonus' => 2.4, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                // Components TE
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Components TE', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Components TE', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Components TE', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Components TE', 'size' => 'M', 'targetCategories' => ['advanced_component']],

                // Equipment ME
                ['name' => 'Standup M-Set Equipment Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Equipment ME', 'size' => 'M', 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Equipment Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Equipment ME', 'size' => 'M', 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Ammunition ME', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Ammunition ME', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Drone ME', 'size' => 'M', 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Drone ME', 'size' => 'M', 'targetCategories' => ['drone', 'fighter']],
                // Equipment TE
                ['name' => 'Standup M-Set Equipment Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Equipment TE', 'size' => 'M', 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Equipment Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Equipment TE', 'size' => 'M', 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Ammunition TE', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Ammunition TE', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Drone TE', 'size' => 'M', 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Drone TE', 'size' => 'M', 'targetCategories' => ['drone', 'fighter']],

                // Structures ME
                ['name' => 'Standup M-Set Structure Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Structures ME', 'size' => 'M', 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup M-Set Structure Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Structures ME', 'size' => 'M', 'targetCategories' => ['structure', 'structure_component']],
                // Structures TE
                ['name' => 'Standup M-Set Structure Manufacturing Time Efficiency I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'M-Set Structures TE', 'size' => 'M', 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup M-Set Structure Manufacturing Time Efficiency II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'M-Set Structures TE', 'size' => 'M', 'targetCategories' => ['structure', 'structure_component']],

                // === L-Set (Large: Azbel) ===

                // Ships
                ['name' => 'Standup L-Set Basic Small Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup L-Set Basic Small Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup L-Set Basic Medium Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup L-Set Basic Medium Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_medium_ship']],
                ['name' => 'Standup L-Set Basic Large Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup L-Set Basic Large Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_large_ship']],
                ['name' => 'Standup L-Set Advanced Small Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup L-Set Advanced Small Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_small_ship']],
                ['name' => 'Standup L-Set Advanced Medium Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup L-Set Advanced Medium Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_medium_ship']],
                ['name' => 'Standup L-Set Advanced Large Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup L-Set Advanced Large Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['advanced_large_ship']],
                ['name' => 'Standup L-Set Capital Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['capital_ship']],
                ['name' => 'Standup L-Set Capital Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['capital_ship']],
                // Components
                ['name' => 'Standup L-Set Basic Capital Component Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Basic Capital Component Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Advanced Component Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup L-Set Advanced Component Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup L-Set Thukker Basic Capital Component Manufacturing Efficiency', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup L-Set Thukker Advanced Component Manufacturing Efficiency', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['advanced_component']],
                // Equipment
                ['name' => 'Standup L-Set Equipment Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Equipment', 'size' => 'L', 'targetCategories' => ['equipment']],
                ['name' => 'Standup L-Set Equipment Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Equipment', 'size' => 'L', 'targetCategories' => ['equipment']],
                ['name' => 'Standup L-Set Ammunition Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ammunition', 'size' => 'L', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup L-Set Ammunition Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ammunition', 'size' => 'L', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup L-Set Drone and Fighter Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Drone', 'size' => 'L', 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup L-Set Drone and Fighter Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Drone', 'size' => 'L', 'targetCategories' => ['drone', 'fighter']],
                // Structures
                ['name' => 'Standup L-Set Structure Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Structures', 'size' => 'L', 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup L-Set Structure Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Structures', 'size' => 'L', 'targetCategories' => ['structure', 'structure_component']],

                // === XL-Set (Sotiyo) ===

                ['name' => 'Standup XL-Set Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'XL-Set Ships', 'size' => 'XL', 'targetCategories' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship']],
                ['name' => 'Standup XL-Set Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'XL-Set Ships', 'size' => 'XL', 'targetCategories' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship']],
                ['name' => 'Standup XL-Set Equipment and Consumable Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'XL-Set Equipment', 'size' => 'XL', 'targetCategories' => ['equipment', 'ammunition', 'drone', 'fighter']],
                ['name' => 'Standup XL-Set Equipment and Consumable Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'XL-Set Equipment', 'size' => 'XL', 'targetCategories' => ['equipment', 'ammunition', 'drone', 'fighter']],
                ['name' => 'Standup XL-Set Structure and Component Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'XL-Set Structures', 'size' => 'XL', 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],
                ['name' => 'Standup XL-Set Structure and Component Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'XL-Set Structures', 'size' => 'XL', 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],
                ['name' => 'Standup XL-Set Thukker Structure and Component Manufacturing Efficiency', 'bonus' => 2.4, 'category' => 'XL-Set Structures', 'size' => 'XL', 'targetCategories' => ['structure', 'structure_component', 'basic_capital_component', 'advanced_component']],

                // === Laboratory (Research/Invention/Copy) ===

                ['name' => 'Standup M-Set Laboratory Optimization I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'Laboratory', 'size' => 'M', 'targetCategories' => []],
                ['name' => 'Standup M-Set Laboratory Optimization II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'Laboratory', 'size' => 'M', 'targetCategories' => []],
                ['name' => 'Standup L-Set Laboratory Optimization I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'Laboratory', 'size' => 'L', 'targetCategories' => []],
                ['name' => 'Standup L-Set Laboratory Optimization II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'Laboratory', 'size' => 'L', 'targetCategories' => []],
                ['name' => 'Standup XL-Set Laboratory Optimization I', 'bonus' => 0, 'timeBonus' => 20.0, 'category' => 'Laboratory', 'size' => 'XL', 'targetCategories' => []],
                ['name' => 'Standup XL-Set Laboratory Optimization II', 'bonus' => 0, 'timeBonus' => 24.0, 'category' => 'Laboratory', 'size' => 'XL', 'targetCategories' => []],
            ],
            'reaction' => [
                // M-Set (Athanor)
                ['name' => 'Standup M-Set Composite Reactor Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['composite_reaction']],
                ['name' => 'Standup M-Set Composite Reactor Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['composite_reaction']],
                ['name' => 'Standup M-Set Biochemical Reactor Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['biochemical_reaction']],
                ['name' => 'Standup M-Set Biochemical Reactor Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['biochemical_reaction']],
                ['name' => 'Standup M-Set Hybrid Reactor Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['hybrid_reaction']],
                ['name' => 'Standup M-Set Hybrid Reactor Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Reactions', 'size' => 'M', 'targetCategories' => ['hybrid_reaction']],
                // L-Set (Tatara)
                ['name' => 'Standup L-Set Reactor Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Reactions', 'size' => 'L', 'targetCategories' => ['composite_reaction', 'biochemical_reaction', 'hybrid_reaction']],
                ['name' => 'Standup L-Set Reactor Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Reactions', 'size' => 'L', 'targetCategories' => ['composite_reaction', 'biochemical_reaction', 'hybrid_reaction']],
            ],
        ];
    }
}
