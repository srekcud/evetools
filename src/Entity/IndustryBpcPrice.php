<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryBpcPriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryBpcPriceRepository::class)]
#[ORM\Table(name: 'industry_bpc_prices')]
#[ORM\UniqueConstraint(columns: ['user_id', 'blueprint_type_id'])]
class IndustryBpcPrice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'integer')]
    private int $blueprintTypeId;

    #[ORM\Column(type: 'float')]
    private float $pricePerRun;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getBlueprintTypeId(): int
    {
        return $this->blueprintTypeId;
    }

    public function setBlueprintTypeId(int $blueprintTypeId): static
    {
        $this->blueprintTypeId = $blueprintTypeId;
        return $this;
    }

    public function getPricePerRun(): float
    {
        return $this->pricePerRun;
    }

    public function setPricePerRun(float $pricePerRun): static
    {
        $this->pricePerRun = $pricePerRun;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
