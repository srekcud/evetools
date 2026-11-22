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
    public const string NPC_STATION_STRUCTURE_TYPE = 'station';

    public const float NPC_STATION_FACILITY_TAX_PERCENT = 0.25;

    /** @var array<string, list<string>> */
    public const array HULLS_OF_LEGACY_TYPE = [
        'engineering_complex' => ['raitaru', 'azbel', 'sotiyo'],
        'refinery' => ['athanor', 'tatara'],
    ];

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

    /** Solar system ID (from SDE) for favorite system filtering */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solarSystemId = null;

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

    /** Facility tax as a percentage (e.g. 3.5 means 3.5%) */
    #[ORM\Column(name: 'facility_tax_rate', type: 'float', nullable: true)]
    private ?float $facilityTaxPercent = null;

    /** Soft-deleted (hidden from user but preserved for corp sharing) */
    #[ORM\Column]
    private bool $isDeleted = false;

    /** @var string[] User IDs who have hidden this structure from their view */
    #[ORM\Column(type: Types::JSON)]
    private array $hiddenByUsers = [];

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

    public function getSolarSystemId(): ?int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(?int $solarSystemId): self
    {
        $this->solarSystemId = $solarSystemId;
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

    public function getFacilityTaxPercent(): ?float
    {
        if ($this->isNpcStation()) {
            return self::NPC_STATION_FACILITY_TAX_PERCENT;
        }

        return $this->facilityTaxPercent;
    }

    public function isNpcStation(): bool
    {
        return isset($this->structureType) && $this->structureType === self::NPC_STATION_STRUCTURE_TYPE;
    }

    public function isHullToSpecify(): bool
    {
        return isset($this->structureType) && \array_key_exists($this->structureType, self::HULLS_OF_LEGACY_TYPE);
    }

    public function setFacilityTaxPercent(?float $facilityTaxPercent): self
    {
        $this->facilityTaxPercent = $facilityTaxPercent;
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

    /** @return string[] */
    public function getHiddenByUsers(): array
    {
        return $this->hiddenByUsers;
    }

    public function isHiddenForUser(string $userId): bool
    {
        return in_array($userId, $this->hiddenByUsers, true);
    }

    public function hideForUser(string $userId): self
    {
        if (!in_array($userId, $this->hiddenByUsers, true)) {
            $this->hiddenByUsers[] = $userId;
        }
        return $this;
    }

    public function unhideForUser(string $userId): self
    {
        $this->hiddenByUsers = array_values(array_filter(
            $this->hiddenByUsers,
            fn (string $id) => $id !== $userId,
        ));
        return $this;
    }
}
