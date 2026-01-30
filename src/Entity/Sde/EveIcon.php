<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\EveIconRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EveIconRepository::class)]
#[ORM\Table(name: 'sde_eve_icons')]
class EveIcon
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $iconId;

    #[ORM\Column(type: 'string', length: 500)]
    private string $iconFile;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    public function getIconId(): int
    {
        return $this->iconId;
    }

    public function setIconId(int $iconId): self
    {
        $this->iconId = $iconId;
        return $this;
    }

    public function getIconFile(): string
    {
        return $this->iconFile;
    }

    public function setIconFile(string $iconFile): self
    {
        $this->iconFile = $iconFile;
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
}
