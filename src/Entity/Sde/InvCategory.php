<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\InvCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvCategoryRepository::class)]
#[ORM\Table(name: 'sde_inv_categories')]
class InvCategory
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $categoryId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $categoryName;

    #[ORM\Column(type: 'boolean')]
    private bool $published = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $iconId = null;

    /** @var Collection<int, InvGroup> */
    #[ORM\OneToMany(targetEntity: InvGroup::class, mappedBy: 'category')]
    private Collection $groups;

    public function __construct()
    {
        $this->groups = new ArrayCollection();
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function setCategoryId(int $categoryId): static
    {
        $this->categoryId = $categoryId;
        return $this;
    }

    public function getCategoryName(): string
    {
        return $this->categoryName;
    }

    public function setCategoryName(string $categoryName): static
    {
        $this->categoryName = $categoryName;
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

    /** @return Collection<int, InvGroup> */
    public function getGroups(): Collection
    {
        return $this->groups;
    }
}
