<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectTemplateRepository::class)]
#[ORM\Table(name: 'industry_project_templates')]
#[ORM\Index(columns: ['owner_id'])]
class IndustryProjectTemplate
{
    public const int MAXIMUM_PER_OWNER = 50;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;
    #[ORM\Column(type: 'string', length: 255)]
    private string $name = '';
    /** @var list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    #[ORM\Column(type: 'json')]
    private array $items = [];
    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $blacklistGroupIds = [];
    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $blacklistTypeIds = [];
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $acceptsOutsideFavoriteSystem = false;
    #[ORM\Column(type: 'float')]
    private float $minimumOutsideSaving = 0.0;
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $sharedWithCorporation = false;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @param list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> $items */
    public function setItems(array $items): static
    {
        $this->items = $items;

        return $this;
    }

    /** @return list<int> */
    public function getBlacklistGroupIds(): array
    {
        return $this->blacklistGroupIds;
    }

    /** @param list<int> $blacklistGroupIds */
    public function setBlacklistGroupIds(array $blacklistGroupIds): static
    {
        $this->blacklistGroupIds = $blacklistGroupIds;

        return $this;
    }

    /** @return list<int> */
    public function getBlacklistTypeIds(): array
    {
        return $this->blacklistTypeIds;
    }

    /** @param list<int> $blacklistTypeIds */
    public function setBlacklistTypeIds(array $blacklistTypeIds): static
    {
        $this->blacklistTypeIds = $blacklistTypeIds;

        return $this;
    }

    public function isAcceptsOutsideFavoriteSystem(): bool
    {
        return $this->acceptsOutsideFavoriteSystem;
    }

    public function setAcceptsOutsideFavoriteSystem(bool $acceptsOutsideFavoriteSystem): static
    {
        $this->acceptsOutsideFavoriteSystem = $acceptsOutsideFavoriteSystem;

        return $this;
    }

    public function getMinimumOutsideSaving(): float
    {
        return $this->minimumOutsideSaving;
    }

    public function setMinimumOutsideSaving(float $minimumOutsideSaving): static
    {
        $this->minimumOutsideSaving = $minimumOutsideSaving;

        return $this;
    }

    public function isSharedWithCorporation(): bool
    {
        return $this->sharedWithCorporation;
    }

    public function setSharedWithCorporation(bool $sharedWithCorporation): static
    {
        $this->sharedWithCorporation = $sharedWithCorporation;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
