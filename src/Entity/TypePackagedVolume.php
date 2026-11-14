<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TypePackagedVolumeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TypePackagedVolumeRepository::class)]
#[ORM\Table(name: 'type_packaged_volumes')]
class TypePackagedVolume
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'float')]
    private float $packagedVolume;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $refreshedAt;

    public function __construct(int $typeId, float $packagedVolume, \DateTimeImmutable $refreshedAt)
    {
        $this->typeId = $typeId;
        $this->packagedVolume = $packagedVolume;
        $this->refreshedAt = $refreshedAt;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getPackagedVolume(): float
    {
        return $this->packagedVolume;
    }

    public function getRefreshedAt(): \DateTimeImmutable
    {
        return $this->refreshedAt;
    }
}
