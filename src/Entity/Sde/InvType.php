<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\InvTypeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvTypeRepository::class)]
#[ORM\Table(name: 'sde_inv_types')]
#[ORM\Index(columns: ['group_id'])]
#[ORM\Index(columns: ['market_group_id'])]
#[ORM\Index(columns: ['published'])]
class InvType
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $typeName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: InvGroup::class, inversedBy: 'types')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'group_id', nullable: false)]
    private InvGroup $group;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $mass = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $volume = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $capacity = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $portionSize = null;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 2, nullable: true)]
    private ?string $basePrice = null;

    #[ORM\Column(type: 'boolean')]
    private bool $published = false;

    #[ORM\ManyToOne(targetEntity: InvMarketGroup::class)]
    #[ORM\JoinColumn(name: 'market_group_id', referencedColumnName: 'market_group_id', nullable: true)]
    private ?InvMarketGroup $marketGroup = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $iconId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $graphicId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $raceId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sofFactionName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $soundId = null;

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getTypeName(): string
    {
        return $this->typeName;
    }

    public function setTypeName(string $typeName): static
    {
        $this->typeName = $typeName;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getGroup(): InvGroup
    {
        return $this->group;
    }

    public function setGroup(InvGroup $group): static
    {
        $this->group = $group;
        return $this;
    }

    public function getMass(): ?float
    {
        return $this->mass;
    }

    public function setMass(?float $mass): static
    {
        $this->mass = $mass;
        return $this;
    }

    public function getVolume(): ?float
    {
        return $this->volume;
    }

    public function setVolume(?float $volume): static
    {
        $this->volume = $volume;
        return $this;
    }

    public function getCapacity(): ?float
    {
        return $this->capacity;
    }

    public function setCapacity(?float $capacity): static
    {
        $this->capacity = $capacity;
        return $this;
    }

    public function getPortionSize(): ?int
    {
        return $this->portionSize;
    }

    public function setPortionSize(?int $portionSize): static
    {
        $this->portionSize = $portionSize;
        return $this;
    }

    public function getBasePrice(): ?string
    {
        return $this->basePrice;
    }

    public function setBasePrice(?string $basePrice): static
    {
        $this->basePrice = $basePrice;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): static
    {
        $this->published = $published;
        return $this;
    }

    public function getMarketGroup(): ?InvMarketGroup
    {
        return $this->marketGroup;
    }

    public function setMarketGroup(?InvMarketGroup $marketGroup): static
    {
        $this->marketGroup = $marketGroup;
        return $this;
    }

    public function getIconId(): ?int
    {
        return $this->iconId;
    }

    public function setIconId(?int $iconId): static
    {
        $this->iconId = $iconId;
        return $this;
    }

    public function getGraphicId(): ?int
    {
        return $this->graphicId;
    }

    public function setGraphicId(?int $graphicId): static
    {
        $this->graphicId = $graphicId;
        return $this;
    }

    public function getRaceId(): ?int
    {
        return $this->raceId;
    }

    public function setRaceId(?int $raceId): static
    {
        $this->raceId = $raceId;
        return $this;
    }

    public function getSofFactionName(): ?int
    {
        return $this->sofFactionName;
    }

    public function setSofFactionName(?int $sofFactionName): static
    {
        $this->sofFactionName = $sofFactionName;
        return $this;
    }

    public function getSoundId(): ?int
    {
        return $this->soundId;
    }

    public function setSoundId(?int $soundId): static
    {
        $this->soundId = $soundId;
        return $this;
    }
}
