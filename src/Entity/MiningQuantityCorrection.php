<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'mining_quantity_corrections')]
#[ORM\Index(columns: ['operation_character_id', 'type_id'])]
class MiningQuantityCorrection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MiningOperationCharacter::class, inversedBy: 'corrections')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MiningOperationCharacter $operationCharacter,
        #[ORM\Column(type: 'integer')]
        private int $typeId,
        #[ORM\Column(type: 'bigint', nullable: true)]
        private ?int $originalQuantity,
        #[ORM\Column(type: 'bigint')]
        private int $correctedQuantity,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $correctedBy,
        #[ORM\Column(type: 'string', length: 255, nullable: true)]
        private ?string $correctedByName,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $correctedAt,
    ) {
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperationCharacter(): MiningOperationCharacter
    {
        return $this->operationCharacter;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getOriginalQuantity(): ?int
    {
        return $this->originalQuantity;
    }

    public function getCorrectedQuantity(): int
    {
        return $this->correctedQuantity;
    }

    public function getCorrectedBy(): ?User
    {
        return $this->correctedBy;
    }

    public function getCorrectedByName(): ?string
    {
        return $this->correctedByName;
    }

    public function getCorrectedAt(): \DateTimeImmutable
    {
        return $this->correctedAt;
    }
}
