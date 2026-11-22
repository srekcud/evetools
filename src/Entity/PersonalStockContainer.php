<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PersonalStockContainerRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PersonalStockContainerRepository::class)]
#[ORM\Table(name: 'personal_stock_containers')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\UniqueConstraint(columns: ['item_id'])]
class PersonalStockContainer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'bigint')]
    private int $itemId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $divisionName;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $stationId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stationName;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, int $itemId, string $name, ?string $divisionName, ?int $stationId, ?string $stationName)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->itemId = $itemId;
        $this->name = $name;
        $this->divisionName = $divisionName;
        $this->stationId = $stationId;
        $this->stationName = $stationName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDivisionName(): ?string
    {
        return $this->divisionName;
    }

    public function getStationId(): ?int
    {
        return $this->stationId;
    }

    public function getStationName(): ?string
    {
        return $this->stationName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
