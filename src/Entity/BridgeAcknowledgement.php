<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BridgeTodoKey;
use App\Repository\BridgeAcknowledgementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BridgeAcknowledgementRepository::class)]
#[ORM\Table(name: 'bridge_acknowledgements')]
#[ORM\UniqueConstraint(columns: ['user_id', 'item_key'])]
class BridgeAcknowledgement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 64, enumType: BridgeTodoKey::class)]
    private BridgeTodoKey $itemKey;

    #[ORM\Column(type: 'integer')]
    private int $acknowledgedCount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getItemKey(): BridgeTodoKey
    {
        return $this->itemKey;
    }

    public function setItemKey(BridgeTodoKey $itemKey): static
    {
        $this->itemKey = $itemKey;
        return $this;
    }

    public function getAcknowledgedCount(): int
    {
        return $this->acknowledgedCount;
    }

    public function setAcknowledgedCount(int $acknowledgedCount): static
    {
        $this->acknowledgedCount = $acknowledgedCount;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
