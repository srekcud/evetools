<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\InvMarketGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvMarketGroupRepository::class)]
#[ORM\Table(name: 'sde_inv_market_groups')]
#[ORM\Index(columns: ['parent_group_id'])]
class InvMarketGroup
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $marketGroupId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $marketGroupName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: InvMarketGroup::class, inversedBy: 'children')]
    #[ORM\JoinColumn(name: 'parent_group_id', referencedColumnName: 'market_group_id', nullable: true)]
    private ?InvMarketGroup $parentGroup = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $iconId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $hasTypes = false;

    /** @var Collection<int, InvMarketGroup> */
    #[ORM\OneToMany(targetEntity: InvMarketGroup::class, mappedBy: 'parentGroup')]
    private Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    public function getMarketGroupId(): int
    {
        return $this->marketGroupId;
    }

    public function setMarketGroupId(int $marketGroupId): static
    {
        $this->marketGroupId = $marketGroupId;
        return $this;
    }

    public function getMarketGroupName(): string
    {
        return $this->marketGroupName;
    }

    public function setMarketGroupName(string $marketGroupName): static
    {
        $this->marketGroupName = $marketGroupName;
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

    public function getParentGroup(): ?InvMarketGroup
    {
        return $this->parentGroup;
    }

    public function setParentGroup(?InvMarketGroup $parentGroup): static
    {
        $this->parentGroup = $parentGroup;
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

    public function hasTypes(): bool
    {
        return $this->hasTypes;
    }

    public function setHasTypes(bool $hasTypes): static
    {
        $this->hasTypes = $hasTypes;
        return $this;
    }

    /** @return Collection<int, InvMarketGroup> */
    public function getChildren(): Collection
    {
        return $this->children;
    }
}
