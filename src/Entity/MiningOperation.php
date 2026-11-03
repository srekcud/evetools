<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

class MiningOperation
{
    private ?Uuid $id = null;

    private User $owner;

    private string $label = '';

    private \DateTimeImmutable $plannedStartAt;

    private MiningOperationStatus $status = MiningOperationStatus::Planned;

    private MiningOperationVisibility $visibility = MiningOperationVisibility::Private;

    private MiningSplitMode $splitMode = MiningSplitMode::ValueProrata;

    private ?int $corporationId = null;

    private string $joinCode = '';

    private \DateTimeImmutable $createdAt;

    private Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->plannedStartAt = $this->createdAt;
    }

    public function getId(): ?Uuid
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

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getPlannedStartAt(): \DateTimeImmutable
    {
        return $this->plannedStartAt;
    }

    public function setPlannedStartAt(\DateTimeImmutable $plannedStartAt): static
    {
        $this->plannedStartAt = $plannedStartAt;

        return $this;
    }

    public function getStatus(): MiningOperationStatus
    {
        return $this->status;
    }

    public function setStatus(MiningOperationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getVisibility(): MiningOperationVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(MiningOperationVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getSplitMode(): MiningSplitMode
    {
        return $this->splitMode;
    }

    public function setSplitMode(MiningSplitMode $splitMode): static
    {
        $this->splitMode = $splitMode;

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

    public function getJoinCode(): string
    {
        return $this->joinCode;
    }

    public function setJoinCode(string $joinCode): static
    {
        $this->joinCode = $joinCode;

        return $this;
    }

    public function renewJoinCode(): static
    {
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(MiningOperationMember $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setOperation($this);
        }

        return $this;
    }

    public function membershipOf(User $user): ?MiningOperationMember
    {
        return null;
    }
}
