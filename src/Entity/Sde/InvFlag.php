<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\InvFlagRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvFlagRepository::class)]
#[ORM\Table(name: 'sde_inv_flags')]
class InvFlag
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $flagId;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $flagName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $flagText = null;

    #[ORM\Column(type: 'integer')]
    private int $orderId = 0;

    public function getFlagId(): int
    {
        return $this->flagId;
    }

    public function setFlagId(int $flagId): self
    {
        $this->flagId = $flagId;
        return $this;
    }

    public function getFlagName(): ?string
    {
        return $this->flagName;
    }

    public function setFlagName(?string $flagName): self
    {
        $this->flagName = $flagName;
        return $this;
    }

    public function getFlagText(): ?string
    {
        return $this->flagText;
    }

    public function setFlagText(?string $flagText): self
    {
        $this->flagText = $flagText;
        return $this;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId(int $orderId): self
    {
        $this->orderId = $orderId;
        return $this;
    }
}
