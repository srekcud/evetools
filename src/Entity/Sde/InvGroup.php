<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\InvGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvGroupRepository::class)]
#[ORM\Table(name: 'sde_inv_groups')]
#[ORM\Index(columns: ['category_id'])]
class InvGroup
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $groupId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $groupName;

    #[ORM\ManyToOne(targetEntity: InvCategory::class, inversedBy: 'groups')]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'category_id', nullable: false)]
    private InvCategory $category;

    #[ORM\Column(type: 'boolean')]
    private bool $published = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $iconId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $useBasePrice = false;

    #[ORM\Column(type: 'boolean')]
    private bool $anchored = false;

    #[ORM\Column(type: 'boolean')]
    private bool $anchorable = false;

    #[ORM\Column(type: 'boolean')]
    private bool $fittableNonSingleton = false;

    /** @var Collection<int, InvType> */
    #[ORM\OneToMany(targetEntity: InvType::class, mappedBy: 'group')]
    private Collection $types;

    public function __construct()
    {
        $this->types = new ArrayCollection();
    }

    public function getGroupId(): int
    {
        return $this->groupId;
    }

    public function setGroupId(int $groupId): static
    {
        $this->groupId = $groupId;
        return $this;
    }

    public function getGroupName(): string
    {
        return $this->groupName;
    }

    public function setGroupName(string $groupName): static
    {
        $this->groupName = $groupName;
        return $this;
    }

    public function getCategory(): InvCategory
    {
        return $this->category;
    }

    public function setCategory(InvCategory $category): static
    {
        $this->category = $category;
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

    public function getIconId(): ?int
    {
        return $this->iconId;
    }

    public function setIconId(?int $iconId): static
    {
        $this->iconId = $iconId;
        return $this;
    }

    public function isUseBasePrice(): bool
    {
        return $this->useBasePrice;
    }

    public function setUseBasePrice(bool $useBasePrice): static
    {
        $this->useBasePrice = $useBasePrice;
        return $this;
    }

    public function isAnchored(): bool
    {
        return $this->anchored;
    }

    public function setAnchored(bool $anchored): static
    {
        $this->anchored = $anchored;
        return $this;
    }

    public function isAnchorable(): bool
    {
        return $this->anchorable;
    }

    public function setAnchorable(bool $anchorable): static
    {
        $this->anchorable = $anchorable;
        return $this;
    }

    public function isFittableNonSingleton(): bool
    {
        return $this->fittableNonSingleton;
    }

    public function setFittableNonSingleton(bool $fittableNonSingleton): static
    {
        $this->fittableNonSingleton = $fittableNonSingleton;
        return $this;
    }

    /** @return Collection<int, InvType> */
    public function getTypes(): Collection
    {
        return $this->types;
    }
}
