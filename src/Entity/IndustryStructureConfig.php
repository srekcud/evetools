<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryStructureConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryStructureConfigRepository::class)]
#[ORM\Table(name: 'industry_structure_configs')]
#[ORM\Index(columns: ['corporation_id', 'location_id'])]
class IndustryStructureConfig
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $name;

    /** ESI location ID for structure sharing (null for custom structures) */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $locationId = null;

    /** Corporation ID for sharing configs within corporation */
    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $corporationId = null;

    #[ORM\Column(length: 20)]
    private string $securityType; // 'highsec', 'lowsec', 'nullsec'

    #[ORM\Column(length: 50)]
    private string $structureType; // 'station', 'engineering_complex', 'refinery'

    /**
     * @var array<string> List of rig names
     */
    #[ORM\Column(type: Types::JSON)]
    private array $rigs = [];

    #[ORM\Column]
    private bool $isDefault = false;

    /** Manually marked as a corporation structure (for sharing even without ESI data) */
    #[ORM\Column]
    private bool $isCorporationStructure = false;

    /** Soft-deleted (hidden from user but preserved for corp sharing) */
    #[ORM\Column]
    private bool $isDeleted = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getSecurityType(): string
    {
        return $this->securityType;
    }

    public function setSecurityType(string $securityType): self
    {
        $this->securityType = $securityType;
        return $this;
    }

    public function getStructureType(): string
    {
        return $this->structureType;
    }

    public function setStructureType(string $structureType): self
    {
        $this->structureType = $structureType;
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getRigs(): array
    {
        return $this->rigs;
    }

    /**
     * @param array<string> $rigs
     */
    public function setRigs(array $rigs): self
    {
        $this->rigs = $rigs;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function setLocationId(?int $locationId): self
    {
        $this->locationId = $locationId;
        return $this;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(?int $corporationId): self
    {
        $this->corporationId = $corporationId;
        return $this;
    }

    public function isCorporationStructure(): bool
    {
        return $this->isCorporationStructure;
    }

    public function setIsCorporationStructure(bool $isCorporationStructure): self
    {
        $this->isCorporationStructure = $isCorporationStructure;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    public function setIsDeleted(bool $isDeleted): self
    {
        $this->isDeleted = $isDeleted;
        return $this;
    }

    /**
     * Calculate the material efficiency bonus for manufacturing based on rigs and security.
     * Returns a percentage (e.g., 4.2 for 4.2% reduction).
     */
    public function getManufacturingMaterialBonus(): float
    {
        $bonus = 0.0;

        foreach ($this->rigs as $rig) {
            $bonus += $this->getRigBonus($rig, 'manufacturing_material');
        }

        // Apply security multiplier
        $bonus *= $this->getSecurityMultiplier();

        return round($bonus, 2);
    }

    /**
     * Calculate the material efficiency bonus for reactions based on rigs and security.
     * Returns a percentage (e.g., 2.4 for 2.4% reduction).
     */
    public function getReactionMaterialBonus(): float
    {
        $bonus = 0.0;

        foreach ($this->rigs as $rig) {
            $bonus += $this->getRigBonus($rig, 'reaction_material');
        }

        // Apply security multiplier
        $bonus *= $this->getSecurityMultiplier();

        return round($bonus, 2);
    }

    /**
     * Calculate the time efficiency bonus for manufacturing.
     * Includes base structure bonus + rig bonuses (multiplicative stacking).
     * Returns a percentage (e.g., 25.0 for 25% time reduction).
     */
    public function getManufacturingTimeBonus(): float
    {
        // Base structure time bonus (only for engineering complexes)
        $baseBonus = match ($this->structureType) {
            'raitaru' => 15.0,
            'azbel' => 20.0,
            'sotiyo' => 30.0,
            'engineering_complex' => 20.0, // Legacy
            default => 0.0,
        };

        // Rig time bonus (only L-Set and XL-Set "Efficiency" rigs, not "Material Efficiency")
        $rigBonus = 0.0;
        foreach ($this->rigs as $rig) {
            $rigBonus += $this->getRigBonus($rig, 'manufacturing_time');
        }

        // Apply security multiplier to rig bonus only
        $rigBonus *= $this->getSecurityMultiplier();

        // Time bonuses stack multiplicatively: 1 - (1 - base) × (1 - rig)
        if ($baseBonus > 0 || $rigBonus > 0) {
            $totalBonus = 1 - (1 - $baseBonus / 100) * (1 - $rigBonus / 100);
            return round($totalBonus * 100, 2);
        }

        return 0.0;
    }

    /**
     * Calculate the time efficiency bonus for reactions.
     * Includes base structure bonus + rig bonuses (multiplicative stacking).
     * Returns a percentage (e.g., 25.0 for 25% time reduction).
     */
    public function getReactionTimeBonus(): float
    {
        // Base structure time bonus (only for refineries)
        $baseBonus = match ($this->structureType) {
            'athanor' => 25.0,
            'tatara' => 25.0,
            'refinery' => 25.0, // Legacy
            default => 0.0,
        };

        // Rig time bonus (L-Set Reactor Efficiency rigs)
        $rigBonus = 0.0;
        foreach ($this->rigs as $rig) {
            $rigBonus += $this->getRigBonus($rig, 'reaction_time');
        }

        // Apply security multiplier to rig bonus only (reactions use lower multipliers)
        $rigBonus *= $this->getReactionSecurityMultiplier();

        // Time bonuses stack multiplicatively: 1 - (1 - base) × (1 - rig)
        if ($baseBonus > 0 || $rigBonus > 0) {
            $totalBonus = 1 - (1 - $baseBonus / 100) * (1 - $rigBonus / 100);
            return round($totalBonus * 100, 2);
        }

        return 0.0;
    }

    private function getSecurityMultiplier(): float
    {
        return match ($this->securityType) {
            'highsec' => 1.0,
            'lowsec' => 1.9,
            'nullsec' => 2.1,
            default => 1.0,
        };
    }

    /**
     * Get security multiplier for reaction rigs (lower than manufacturing).
     */
    private function getReactionSecurityMultiplier(): float
    {
        return match ($this->securityType) {
            'highsec' => 1.0,
            'lowsec' => 1.0,
            'nullsec' => 1.1,
            default => 1.0,
        };
    }

    /**
     * Get the base bonus for a specific rig type.
     * Uses pattern matching to support all rig sizes (M, L, XL).
     */
    private function getRigBonus(string $rigName, string $bonusType): float
    {
        // Check if this is a manufacturing or reaction rig
        $isManufacturing = str_contains($rigName, 'Manufacturing');
        $isReaction = str_contains($rigName, 'Reactor');

        // Check if this is an "Efficiency" rig (provides time bonus) vs "Material Efficiency" rig (ME only)
        // L-Set and XL-Set "Efficiency" rigs have time bonuses
        // M-Set "Material Efficiency" rigs do NOT have time bonuses
        $isEfficiencyRig = str_contains($rigName, 'Efficiency') && !str_contains($rigName, 'Material Efficiency');
        $isLargeOrXL = str_contains($rigName, 'L-Set') || str_contains($rigName, 'XL-Set');

        // Material bonuses
        if ($bonusType === 'manufacturing_material' && !$isManufacturing) {
            return 0.0;
        }
        if ($bonusType === 'reaction_material' && !$isReaction) {
            return 0.0;
        }

        // Time bonuses - only for L-Set/XL-Set "Efficiency" rigs (not "Material Efficiency")
        if ($bonusType === 'manufacturing_time') {
            if (!$isManufacturing || !$isLargeOrXL || !$isEfficiencyRig) {
                return 0.0;
            }
        }
        if ($bonusType === 'reaction_time') {
            // Reactor Efficiency rigs provide time bonuses
            if (!$isReaction || !str_contains($rigName, 'Reactor Efficiency')) {
                return 0.0;
            }
        }

        // Thukker rigs (faction) are equivalent to T2
        if (str_contains($rigName, 'Thukker')) {
            return 2.4;
        }

        // T2 rigs end with "II"
        if (str_ends_with($rigName, ' II')) {
            return 2.4;
        }

        // T1 rigs end with "I"
        if (str_ends_with($rigName, ' I')) {
            return 2.0;
        }

        return 0.0;
    }
}
