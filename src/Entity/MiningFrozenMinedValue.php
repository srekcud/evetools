<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'mining_frozen_mined_values')]
#[ORM\UniqueConstraint(columns: ['operation_id', 'position'])]
class MiningFrozenMinedValue
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MiningOperation::class, inversedBy: 'frozenMinedValues')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MiningOperation $operation,
        #[ORM\ManyToOne(targetEntity: MiningOperationMember::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?MiningOperationMember $member,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $participantName,
        #[ORM\Column(type: 'smallint')]
        private int $position,
        #[ORM\Column(type: 'float')]
        private float $minedValue,
        #[ORM\Column(type: 'float', options: ['default' => 0])]
        private float $deliveredValue = 0.0,
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

    public function getDeliveredValue(): float
    {
        return $this->deliveredValue;
    }
}
