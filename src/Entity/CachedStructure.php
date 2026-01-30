<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CachedStructureRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CachedStructureRepository::class)]
#[ORM\Table(name: 'cached_structure')]
#[ORM\Index(columns: ['structure_id'], name: 'idx_cached_structure_id')]
class CachedStructure
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint', unique: true)]
    private int $structureId;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(nullable: true)]
    private ?int $solarSystemId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $resolvedAt;

    public function __construct()
    {
        $this->resolvedAt = new \DateTimeImmutable();
    }

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSolarSystemId(): ?int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(?int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;
        return $this;
    }

    public function getResolvedAt(): \DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }
}
