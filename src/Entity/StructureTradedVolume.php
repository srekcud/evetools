<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StructureTradedVolumeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: StructureTradedVolumeRepository::class)]
#[ORM\Table(name: 'structure_traded_volumes')]
#[ORM\UniqueConstraint(columns: ['structure_id', 'type_id', 'date'])]
#[ORM\Index(columns: ['structure_id', 'type_id'])]
#[ORM\Index(columns: ['date'])]
class StructureTradedVolume
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $structureId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'bigint', options: ['default' => 0])]
    private int $tradedVolume = 0;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getStructureId(): int
    {
        return $this->structureId;
    }

    public function setStructureId(int $structureId): static
    {
        $this->structureId = $structureId;

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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getTradedVolume(): int
    {
        return $this->tradedVolume;
    }

    public function setTradedVolume(int $tradedVolume): static
    {
        $this->tradedVolume = $tradedVolume;

        return $this;
    }
}
