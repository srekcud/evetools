<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CorporationMiningExtractionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CorporationMiningExtractionRepository::class)]
#[ORM\Table(name: 'corporation_mining_extractions')]
#[ORM\UniqueConstraint(name: 'uniq_corporation_mining_extraction', columns: ['corporation_id', 'structure_id', 'moon_id', 'extraction_start_time'])]
class CorporationMiningExtraction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    #[ORM\Column(type: 'bigint')]
    private int $structureId;

    #[ORM\Column(type: 'bigint')]
    private int $moonId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $extractionStartTime;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $chunkArrivalTime;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $naturalDecayTime;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $structureName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solarSystemId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $solarSystemName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $moonName = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCorporationId(): int
    {
        return $this->corporationId;
    }

    public function setCorporationId(int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
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

    public function getMoonId(): int
    {
        return $this->moonId;
    }

    public function setMoonId(int $moonId): static
    {
        $this->moonId = $moonId;

        return $this;
    }

    public function getExtractionStartTime(): \DateTimeImmutable
    {
        return $this->extractionStartTime;
    }

    public function setExtractionStartTime(\DateTimeImmutable $extractionStartTime): static
    {
        $this->extractionStartTime = $extractionStartTime;

        return $this;
    }

    public function getChunkArrivalTime(): \DateTimeImmutable
    {
        return $this->chunkArrivalTime;
    }

    public function setChunkArrivalTime(\DateTimeImmutable $chunkArrivalTime): static
    {
        $this->chunkArrivalTime = $chunkArrivalTime;

        return $this;
    }

    public function getNaturalDecayTime(): \DateTimeImmutable
    {
        return $this->naturalDecayTime;
    }

    public function setNaturalDecayTime(\DateTimeImmutable $naturalDecayTime): static
    {
        $this->naturalDecayTime = $naturalDecayTime;

        return $this;
    }

    public function getStructureName(): ?string
    {
        return $this->structureName;
    }

    public function setStructureName(?string $structureName): static
    {
        $this->structureName = $structureName;

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

    public function getSolarSystemName(): ?string
    {
        return $this->solarSystemName;
    }

    public function setSolarSystemName(?string $solarSystemName): static
    {
        $this->solarSystemName = $solarSystemName;

        return $this;
    }

    public function getMoonName(): ?string
    {
        return $this->moonName;
    }

    public function setMoonName(?string $moonName): static
    {
        $this->moonName = $moonName;

        return $this;
    }
}
