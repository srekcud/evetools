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
                // M-Set (Medium: Raitaru) - Ships ME
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
                // Components ME
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Basic Capital Component Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['basic_capital_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup M-Set Advanced Component Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Components ME', 'size' => 'M', 'targetCategories' => ['advanced_component']],
                // Equipment ME
                ['name' => 'Standup M-Set Equipment Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Equipment ME', 'size' => 'M', 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Equipment Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Equipment ME', 'size' => 'M', 'targetCategories' => ['equipment']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Equipment ME', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Ammunition Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Equipment ME', 'size' => 'M', 'targetCategories' => ['ammunition']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Equipment ME', 'size' => 'M', 'targetCategories' => ['drone', 'fighter']],
                ['name' => 'Standup M-Set Drone and Fighter Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Equipment ME', 'size' => 'M', 'targetCategories' => ['drone', 'fighter']],
                // Structures ME
                ['name' => 'Standup M-Set Structure Manufacturing Material Efficiency I', 'bonus' => 2.0, 'category' => 'M-Set Structures ME', 'size' => 'M', 'targetCategories' => ['structure', 'structure_component']],
                ['name' => 'Standup M-Set Structure Manufacturing Material Efficiency II', 'bonus' => 2.4, 'category' => 'M-Set Structures ME', 'size' => 'M', 'targetCategories' => ['structure', 'structure_component']],
                // L-Set (Large: Azbel)
                ['name' => 'Standup L-Set Basic Small Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup L-Set Basic Small Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['basic_small_ship']],
                ['name' => 'Standup L-Set Advanced Component Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup L-Set Advanced Component Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Components', 'size' => 'L', 'targetCategories' => ['advanced_component']],
                ['name' => 'Standup L-Set Capital Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['capital_ship']],
                ['name' => 'Standup L-Set Capital Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'L-Set Ships', 'size' => 'L', 'targetCategories' => ['capital_ship']],
                // XL-Set (Sotiyo)
                ['name' => 'Standup XL-Set Ship Manufacturing Efficiency I', 'bonus' => 2.0, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship']],
                ['name' => 'Standup XL-Set Ship Manufacturing Efficiency II', 'bonus' => 2.4, 'category' => 'XL-Set', 'size' => 'XL', 'targetCategories' => ['basic_small_ship', 'basic_medium_ship', 'basic_large_ship', 'advanced_small_ship', 'advanced_medium_ship', 'advanced_large_ship', 'capital_ship']],
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
