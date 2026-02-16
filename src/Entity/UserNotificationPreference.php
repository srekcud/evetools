<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserNotificationPreferenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserNotificationPreferenceRepository::class)]
#[ORM\Table(name: 'user_notification_preferences')]
#[ORM\UniqueConstraint(columns: ['user_id', 'category'])]
class UserNotificationPreference
{
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

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $thresholdMinutes = null;

    #[ORM\Column(type: 'boolean')]
    private bool $pushEnabled = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getThresholdMinutes(): ?int
    {
        return $this->thresholdMinutes;
    }

    public function setThresholdMinutes(?int $thresholdMinutes): static
    {
        $this->thresholdMinutes = $thresholdMinutes;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function isPushEnabled(): bool
    {
        return $this->pushEnabled;
    }

    public function setPushEnabled(bool $pushEnabled): static
    {
        $this->pushEnabled = $pushEnabled;
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
