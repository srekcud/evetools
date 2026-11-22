<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StructureAccessSource;
use App\Enum\StructureAccessStatus;
use App\Repository\CharacterStructureAccessRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CharacterStructureAccessRepository::class)]
#[ORM\Table(name: 'character_structure_access')]
#[ORM\Index(columns: ['character_id'])]
#[ORM\UniqueConstraint(columns: ['character_id', 'structure_id'])]
class CharacterStructureAccess
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(type: 'bigint')]
    private int $structureId;

    #[ORM\Column(type: 'string', length: 16, enumType: StructureAccessStatus::class)]
    private StructureAccessStatus $status;

    #[ORM\Column(type: 'string', length: 16, enumType: StructureAccessSource::class)]
    private StructureAccessSource $source;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $checkedAt;

    public function __construct(
        Character $character,
        int $structureId,
        StructureAccessStatus $status,
        StructureAccessSource $source,
        \DateTimeImmutable $checkedAt,
    ) {
        $this->assertStored($status);
        $this->id = Uuid::v7();
        $this->character = $character;
        $this->structureId = $structureId;
        $this->status = $status;
        $this->source = $source;
        $this->checkedAt = $checkedAt;
    }

    public function record(StructureAccessStatus $status, StructureAccessSource $source, \DateTimeImmutable $checkedAt): void
    {
        $this->assertStored($status);
        $this->status = $status;
        $this->source = $source;
        $this->checkedAt = $checkedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getStructureId(): int
    {
        return $this->structureId;
    }

    public function getStatus(): StructureAccessStatus
    {
        return $this->status;
    }

    public function getSource(): StructureAccessSource
    {
        return $this->source;
    }

    public function getCheckedAt(): \DateTimeImmutable
    {
        return $this->checkedAt;
    }

    private function assertStored(StructureAccessStatus $status): void
    {
        if (StructureAccessStatus::Unknown === $status) {
            throw new \InvalidArgumentException('An unknown access is never stored: no row means unknown.');
        }
    }
}
