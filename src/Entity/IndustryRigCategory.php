<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryRigCategoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Maps EVE SDE group IDs to industry rig categories.
 * This determines which structure rig bonus applies to which products.
 */
#[ORM\Entity(repositoryClass: IndustryRigCategoryRepository::class)]
#[ORM\Table(name: 'industry_rig_categories')]
#[ORM\Index(columns: ['group_id'], name: 'idx_rig_category_group')]
class IndustryRigCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Category key matching the rig category (e.g., 'basic_small_ship', 'advanced_component')
     */
    #[ORM\Column(length: 50)]
    private string $category;

    /**
     * EVE SDE group_id that belongs to this category
     */
    #[ORM\Column]
    private int $groupId;

    /**
     * Human-readable description
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $description = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;
        return $this;
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function setGroupId(int $groupId): self
    {
        $this->groupId = $groupId;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * All valid category keys and their descriptions.
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            // Manufacturing categories
            'basic_small_ship' => 'T1 Frigates, Destroyers, Shuttles, Corvettes',
            'basic_medium_ship' => 'T1 Cruisers, Battlecruisers, Industrials, Mining Barges',
            'basic_large_ship' => 'T1 Battleships',
            'advanced_small_ship' => 'T2/Faction Frigates, Destroyers (Interceptors, Assault Frigs, etc.)',
            'advanced_medium_ship' => 'T2/Faction Cruisers, Battlecruisers (HACs, Logistics, etc.)',
            'advanced_large_ship' => 'T2/Faction Battleships (Marauders, Black Ops)',
            'capital_ship' => 'Capitals, Supercapitals, Freighters',
            'basic_capital_component' => 'Capital Construction Components',
            'advanced_component' => 'T2 Components, T3 Components',
            'structure_component' => 'Structure Components',
            'equipment' => 'Modules, Deployables, Cargo Containers',
            'ammunition' => 'Charges, Scripts, Bombs, Probes',
            'drone' => 'Combat, Mining, Utility Drones',
            'fighter' => 'Fighters (Light, Heavy, Support)',
            'structure' => 'Upwell Structures (Citadels, Engineering Complexes, Refineries)',
            // Reaction categories
            'composite_reaction' => 'Composite Reactions (Moon -> Composites)',
            'biochemical_reaction' => 'Biochemical Reactions (Boosters)',
            'hybrid_reaction' => 'Hybrid Reactions (T3 Materials)',
        ];
    }
}
