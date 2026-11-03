<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReprocessingFailure;
use App\Enum\ReprocessingLocation;
use App\Enum\ReprocessingRigTier;
use App\Enum\ReprocessingSecurity;
use App\Repository\ReprocessingProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ReprocessingProfileRepository::class)]
#[ORM\Table(name: 'user_reprocessing_profiles')]
class ReprocessingProfile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(type: 'integer')]
    private int $oreProcessingSkillTypeId;

    #[ORM\Column(type: 'string', length: 20, enumType: ReprocessingLocation::class)]
    private ReprocessingLocation $location;

    #[ORM\Column(type: 'string', length: 10, nullable: true, enumType: ReprocessingRigTier::class)]
    private ?ReprocessingRigTier $rigTier = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true, enumType: ReprocessingSecurity::class)]
    private ?ReprocessingSecurity $security = null;

    #[ORM\Column(type: 'smallint')]
    private int $implantPercent = 0;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $reprocessingLevel = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $reprocessingEfficiencyLevel = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $oreProcessingLevel = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $reprocessYield = null;

    #[ORM\Column(type: 'string', length: 20, nullable: true, enumType: ReprocessingFailure::class)]
    private ?ReprocessingFailure $failure = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $computedAt;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function setCharacter(Character $character): static
    {
        $this->character = $character;

        return $this;
    }

    public function getOreProcessingSkillTypeId(): int
    {
        return $this->oreProcessingSkillTypeId;
    }

    public function setOreProcessingSkillTypeId(int $oreProcessingSkillTypeId): static
    {
        $this->oreProcessingSkillTypeId = $oreProcessingSkillTypeId;

        return $this;
    }

    public function getLocation(): ReprocessingLocation
    {
        return $this->location;
    }

    public function setLocation(ReprocessingLocation $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getRigTier(): ?ReprocessingRigTier
    {
        return $this->rigTier;
    }

    public function setRigTier(?ReprocessingRigTier $rigTier): static
    {
        $this->rigTier = $rigTier;

        return $this;
    }

    public function getSecurity(): ?ReprocessingSecurity
    {
        return $this->security;
    }

    public function setSecurity(?ReprocessingSecurity $security): static
    {
        $this->security = $security;

        return $this;
    }

    public function getImplantPercent(): int
    {
        return $this->implantPercent;
    }

    public function setImplantPercent(int $implantPercent): static
    {
        $this->implantPercent = $implantPercent;

        return $this;
    }

    public function getReprocessingLevel(): ?int
    {
        return $this->reprocessingLevel;
    }

    public function setReprocessingLevel(?int $reprocessingLevel): static
    {
        $this->reprocessingLevel = $reprocessingLevel;

        return $this;
    }

    public function getReprocessingEfficiencyLevel(): ?int
    {
        return $this->reprocessingEfficiencyLevel;
    }

    public function setReprocessingEfficiencyLevel(?int $reprocessingEfficiencyLevel): static
    {
        $this->reprocessingEfficiencyLevel = $reprocessingEfficiencyLevel;

        return $this;
    }

    public function getOreProcessingLevel(): ?int
    {
        return $this->oreProcessingLevel;
    }

    public function setOreProcessingLevel(?int $oreProcessingLevel): static
    {
        $this->oreProcessingLevel = $oreProcessingLevel;

        return $this;
    }

    public function getReprocessYield(): ?float
    {
        return $this->reprocessYield;
    }

    public function setReprocessYield(?float $reprocessYield): static
    {
        $this->reprocessYield = $reprocessYield;

        return $this;
    }

    public function getFailure(): ?ReprocessingFailure
    {
        return $this->failure;
    }

    public function setFailure(?ReprocessingFailure $failure): static
    {
        $this->failure = $failure;

        return $this;
    }

    public function getComputedAt(): \DateTimeImmutable
    {
        return $this->computedAt;
    }

    public function setComputedAt(\DateTimeImmutable $computedAt): static
    {
        $this->computedAt = $computedAt;

        return $this;
    }
}
