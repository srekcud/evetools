<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notifications')]
#[ORM\Index(columns: ['user_id', 'is_read', 'created_at'])]
#[ORM\Index(columns: ['user_id', 'category', 'created_at'])]
class Notification
{
    public const CATEGORY_PLANETARY = 'planetary';
    public const CATEGORY_INDUSTRY = 'industry';
    public const CATEGORY_ESCALATION = 'escalation';
    public const CATEGORY_ESI = 'esi';
    public const CATEGORY_PRICE = 'price';

    public const LEVEL_INFO = 'info';
    public const LEVEL_SUCCESS = 'success';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_CRITICAL = 'critical';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 30)]
    private string $category;

    #[ORM\Column(type: 'string', length: 10)]
    private string $level;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $message;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $data = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $route = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isRead = false;

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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): static
    {
        $this->level = $level;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getData(): ?array
    {
        return $this->data;
    }

    /** @param array<string, mixed>|null $data */
    public function setData(?array $data): static
    {
        $this->data = $data;
        return $this;
    }

    public function getRoute(): ?string
    {
        return $this->route;
    }

    public function setRoute(?string $route): static
    {
        $this->route = $route;
        return $this;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function setIsRead(bool $isRead): static
    {
        $this->isRead = $isRead;
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
}
