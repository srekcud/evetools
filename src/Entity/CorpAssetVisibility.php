<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CorpAssetVisibilityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CorpAssetVisibilityRepository::class)]
#[ORM\Table(name: 'corp_asset_visibility')]
#[ORM\UniqueConstraint(columns: ['corporation_id'])]
class CorpAssetVisibility
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    /** @var int[] Division numbers (1-7) that are visible to corp members */
    #[ORM\Column(type: 'json')]
    private array $visibleDivisions = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $configuredBy;

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

    public function getCorporationId(): int
    {
        return $this->corporationId;
    }

    public function setCorporationId(int $corporationId): static
    {
        $this->corporationId = $corporationId;
        return $this;
    }

    /** @return int[] */
    public function getVisibleDivisions(): array
    {
        return $this->visibleDivisions;
    }

    /** @param int[] $visibleDivisions */
    public function setVisibleDivisions(array $visibleDivisions): static
    {
        $this->visibleDivisions = $visibleDivisions;
        return $this;
    }

    public function getConfiguredBy(): User
    {
        return $this->configuredBy;
    }

    public function setConfiguredBy(User $configuredBy): static
    {
        $this->configuredBy = $configuredBy;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
