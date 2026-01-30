<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\ChrRaceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChrRaceRepository::class)]
#[ORM\Table(name: 'sde_chr_races')]
class ChrRace
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $raceId;

    #[ORM\Column(type: 'string', length: 100)]
    private string $raceName;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $iconId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $shortDescription = null;

    public function getRaceId(): int
    {
        return $this->raceId;
    }

    public function setRaceId(int $raceId): self
    {
        $this->raceId = $raceId;
        return $this;
    }

    public function getRaceName(): string
    {
        return $this->raceName;
    }

    public function setRaceName(string $raceName): self
    {
        $this->raceName = $raceName;
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

    public function getIconId(): ?int
    {
        return $this->iconId;
    }

    public function setIconId(?int $iconId): self
    {
        $this->iconId = $iconId;
        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): self
    {
        $this->shortDescription = $shortDescription;
        return $this;
    }
}
