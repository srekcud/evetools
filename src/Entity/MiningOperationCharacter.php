<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

class MiningOperationCharacter
{
    private ?Uuid $id = null;

    private \DateTimeImmutable $enrolledAt;

    public function __construct(
        private MiningOperationMember $member,
        private Character $character,
    ) {
        $this->enrolledAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getMember(): MiningOperationMember
    {
        return $this->member;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getEnrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }
}
