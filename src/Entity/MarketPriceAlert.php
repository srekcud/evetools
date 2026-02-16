<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MarketPriceAlertRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MarketPriceAlertRepository::class)]
#[ORM\Table(name: 'market_price_alerts')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\Index(columns: ['status'])]
class MarketPriceAlert
{
    public const DIRECTION_ABOVE = 'above';
    public const DIRECTION_BELOW = 'below';

    public const SOURCE_JITA_SELL = 'jita_sell';
    public const SOURCE_JITA_BUY = 'jita_buy';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIGGERED = 'triggered';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $typeName;

    #[ORM\Column(type: 'string', length: 10)]
    private string $direction;

    #[ORM\Column(type: 'float')]
    private float $threshold;

    #[ORM\Column(type: 'string', length: 20)]
    private string $priceSource;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_ACTIVE;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $triggeredAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getTypeName(): string
    {
        return $this->typeName;
    }

    public function setTypeName(string $typeName): static
    {
        $this->typeName = $typeName;
        return $this;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): static
    {
        $this->direction = $direction;
        return $this;
    }

    public function getThreshold(): float
    {
        return $this->threshold;
    }

    public function setThreshold(float $threshold): static
    {
        $this->threshold = $threshold;
        return $this;
    }

    public function getPriceSource(): string
    {
        return $this->priceSource;
    }

    public function setPriceSource(string $priceSource): static
    {
        $this->priceSource = $priceSource;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getTriggeredAt(): ?\DateTimeImmutable
    {
        return $this->triggeredAt;
    }

    public function setTriggeredAt(?\DateTimeImmutable $triggeredAt): static
    {
        $this->triggeredAt = $triggeredAt;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isOwnedBy(User $user): bool
    {
        $thisId = $this->user->getId();
        $otherId = $user->getId();

        if ($thisId === null || $otherId === null) {
            return false;
        }

        return $thisId->equals($otherId);
    }

    public function trigger(): static
    {
        $this->status = self::STATUS_TRIGGERED;
        $this->triggeredAt = new \DateTimeImmutable();
        return $this;
    }
}
