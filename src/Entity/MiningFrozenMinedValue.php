<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

class MiningFrozenMinedValue
{
    private ?Uuid $id = null;

    public function __construct(
        private MiningOperation $operation,
        private ?MiningOperationMember $member,
        private ?string $participantName,
        private int $position,
        private float $minedValue,
    ) {
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperation(): MiningOperation
    {
        return $this->operation;
    }

    public function getMember(): ?MiningOperationMember
    {
        return $this->member;
    }

    public function getParticipantName(): ?string
    {
        return $this->participantName;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getMinedValue(): float
    {
        return $this->minedValue;
    }
}
