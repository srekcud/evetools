<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

class MiningOperationMember
{
    private ?Uuid $id = null;

    private MiningOperation $operation;

    private User $user;

    private MiningOperationMemberRole $role = MiningOperationMemberRole::Member;

    private MiningOperationMemberStatus $status = MiningOperationMemberStatus::Pending;

    private \DateTimeImmutable $joinedAt;

    private Collection $characters;

    public function __construct()
    {
        $this->characters = new ArrayCollection();
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperation(): MiningOperation
    {
        return $this->operation;
    }

    public function setOperation(MiningOperation $operation): static
    {
        $this->operation = $operation;

        return $this;
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

    public function getRole(): MiningOperationMemberRole
    {
        return $this->role;
    }

    public function setRole(MiningOperationMemberRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): MiningOperationMemberStatus
    {
        return $this->status;
    }

    public function setStatus(MiningOperationMemberStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    public function getCharacters(): Collection
    {
        return $this->characters;
    }

    public function enrol(Character $character): static
    {
        $this->characters->add(new MiningOperationCharacter($this, $character));

        return $this;
    }

    public function remove(): static
    {
        return $this;
    }

    public function requestRejoin(array $characters): static
    {
        return $this;
    }
}
