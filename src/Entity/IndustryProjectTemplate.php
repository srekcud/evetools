<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

class IndustryProjectTemplate
{
    public const int MAXIMUM_PER_OWNER = 50;

    private Uuid $id;
    private User $owner;
    private string $name = '';
    /** @var list<array{typeId: int, typeName: string, meLevel: int, teLevel: int, runs: int}> */
    private array $items = [];
    /** @var list<int> */
    private array $blacklistGroupIds = [];
    /** @var list<int> */
    private array $blacklistTypeIds = [];
    private bool $acceptsOutsideFavoriteSystem = false;
    private float $minimumOutsideSaving = 0.0;
    private bool $sharedWithCorporation = false;
    private \DateTimeImmutable $createdAt;
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
