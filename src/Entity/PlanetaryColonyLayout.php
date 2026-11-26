<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanetaryColonyLayoutRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlanetaryColonyLayoutRepository::class)]
#[ORM\Table(name: 'planetary_colony_layouts')]
#[ORM\Index(columns: ['user_id', 'created_at'])]
class PlanetaryColonyLayout
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 100)]
    private string $name;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $template = [];

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $public = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getTemplate(): array
    {
        return $this->template;
    }

    /** @param array<string, mixed> $template */
    public function setTemplate(array $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function publish(\DateTimeImmutable $at): static
    {
        $this->public = true;
        $this->publishedAt = $at;

        return $this;
    }

    public function unpublish(): static
    {
        $this->public = false;
        $this->publishedAt = null;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
