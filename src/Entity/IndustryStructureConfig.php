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
     * Get the base bonus for a specific rig type.
     * Uses pattern matching to support all rig sizes (M, L, XL).
     */
    private function getRigBonus(string $rigName, string $bonusType): float
    {
        // Check if this is a manufacturing or reaction rig
        $isManufacturing = str_contains($rigName, 'Manufacturing');
        $isReaction = str_contains($rigName, 'Reactor');

        if ($bonusType === 'manufacturing_material' && !$isManufacturing) {
            return 0.0;
        }
        if ($bonusType === 'reaction_material' && !$isReaction) {
            return 0.0;
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
