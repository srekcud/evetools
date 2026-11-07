<?php

declare(strict_types=1);

namespace App\Entity;

class OwnedBlueprint
{
    private const int COPY_QUANTITY = -2;

    private int $itemId;
    private int $typeId;
    private int $locationId;
    private string $locationFlag;
    private int $quantity;
    private int $materialEfficiency;
    private int $timeEfficiency;
    private int $runs;
    private ?Character $character = null;
    private ?int $corporationId = null;
    private ?int $divisionNumber = null;
    private \DateTimeImmutable $syncedAt;

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function setItemId(int $itemId): static
    {
        $this->itemId = $itemId;

        return $this;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;

        return $this;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function setLocationId(int $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }

    public function getLocationFlag(): string
    {
        return $this->locationFlag;
    }

    public function setLocationFlag(string $locationFlag): static
    {
        $this->locationFlag = $locationFlag;

        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getMaterialEfficiency(): int
    {
        return $this->materialEfficiency;
    }

    public function setMaterialEfficiency(int $materialEfficiency): static
    {
        $this->materialEfficiency = $materialEfficiency;

        return $this;
    }

    public function getTimeEfficiency(): int
    {
        return $this->timeEfficiency;
    }

    public function setTimeEfficiency(int $timeEfficiency): static
    {
        $this->timeEfficiency = $timeEfficiency;

        return $this;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function setRuns(int $runs): static
    {
        $this->runs = $runs;

        return $this;
    }

    public function getCharacter(): ?Character
    {
        return $this->character;
    }

    public function setCharacter(?Character $character): static
    {
        $this->character = $character;

        return $this;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(?int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
    }

    public function getDivisionNumber(): ?int
    {
        return $this->divisionNumber;
    }

    public function setDivisionNumber(?int $divisionNumber): static
    {
        $this->divisionNumber = $divisionNumber;

        return $this;
    }

    public function getSyncedAt(): \DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function setSyncedAt(\DateTimeImmutable $syncedAt): static
    {
        $this->syncedAt = $syncedAt;

        return $this;
    }

    public function isOriginal(): bool
    {
        return false;
    }

    public function isCopy(): bool
    {
        return false;
    }
}
