<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\ChrFactionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChrFactionRepository::class)]
#[ORM\Table(name: 'sde_chr_factions')]
class ChrFaction
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $factionId;

    #[ORM\Column(type: 'string', length: 100)]
    private string $factionName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $raceIds = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solarSystemId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $corporationId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $sizeFactor = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $stationCount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $stationSystemCount = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $militiaCorporationId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $iconId = null;

    public function getFactionId(): int
    {
        return $this->factionId;
    }

    public function setFactionId(int $factionId): self
    {
        $this->factionId = $factionId;
        return $this;
    }

    public function getFactionName(): string
    {
        return $this->factionName;
    }

    public function setFactionName(string $factionName): self
    {
        $this->factionName = $factionName;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getRaceIds(): ?int
    {
        return $this->raceIds;
    }

    public function setRaceIds(?int $raceIds): self
    {
        $this->raceIds = $raceIds;
        return $this;
    }

    public function getSolarSystemId(): ?int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(?int $solarSystemId): self
    {
        $this->solarSystemId = $solarSystemId;
        return $this;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(?int $corporationId): self
    {
        $this->corporationId = $corporationId;
        return $this;
    }

    public function getSizeFactor(): ?float
    {
        return $this->sizeFactor;
    }

    public function setSizeFactor(?float $sizeFactor): self
    {
        $this->sizeFactor = $sizeFactor;
        return $this;
    }

    public function getStationCount(): ?int
    {
        return $this->stationCount;
    }

    public function setStationCount(?int $stationCount): self
    {
        $this->stationCount = $stationCount;
        return $this;
    }

    public function getStationSystemCount(): ?int
    {
        return $this->stationSystemCount;
    }

    public function setStationSystemCount(?int $stationSystemCount): self
    {
        $this->stationSystemCount = $stationSystemCount;
        return $this;
    }

    public function getMilitiaCorporationId(): ?int
    {
        return $this->militiaCorporationId;
    }

    public function setMilitiaCorporationId(?int $militiaCorporationId): self
    {
        $this->militiaCorporationId = $militiaCorporationId;
        return $this;
    }

    public function getIconId(): ?int
    {
        return $this->iconId;
    }

    public function setIconId(?int $iconId): self
    {
        $this->iconId = $iconId;
        return $this;
    }
}
