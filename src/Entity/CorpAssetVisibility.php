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

    /** @var array<int, list<string>> */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $restrictedDivisions = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $shareBlueprintsOfHiddenDivisions = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $configuredBy = null;

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

    /** @return array<int, list<string>> */
    public function getRestrictedDivisions(): array
    {
        return $this->restrictedDivisions;
    }

    /** @param array<int, list<string>> $restrictedDivisions */
    public function setRestrictedDivisions(array $restrictedDivisions): static
    {
        $this->restrictedDivisions = $restrictedDivisions;
        return $this;
    }

    public function sharesBlueprintsOfHiddenDivisions(): bool
    {
        return $this->shareBlueprintsOfHiddenDivisions;
    }

    public function setShareBlueprintsOfHiddenDivisions(bool $shareBlueprintsOfHiddenDivisions): static
    {
        $this->shareBlueprintsOfHiddenDivisions = $shareBlueprintsOfHiddenDivisions;
        return $this;
    }

    public function showsBlueprintIn(?int $division, ?User $viewer): bool
    {
        if ($this->shareBlueprintsOfHiddenDivisions) {
            return true;
        }

        return $division !== null && $this->isVisibleTo($division, $viewer);
    }

    public function hidesEveryBlueprintFrom(?User $viewer): bool
    {
        return !$this->shareBlueprintsOfHiddenDivisions && $this->divisionsVisibleTo($viewer) === [];
    }

    public function isVisibleTo(int $division, ?User $viewer): bool
    {
        if (in_array($division, $this->visibleDivisions, true)) {
            return true;
        }

        $viewerId = $viewer?->getId();
        if ($viewerId === null) {
            return false;
        }

        return in_array($viewerId->toRfc4122(), $this->restrictedDivisions[$division] ?? [], true);
    }

    /** @return list<int> */
    public function divisionsVisibleTo(?User $viewer): array
    {
        $divisions = array_unique([
            ...array_map(intval(...), $this->visibleDivisions),
            ...array_map(intval(...), array_keys($this->restrictedDivisions)),
        ]);
        $visibleDivisions = array_values(array_filter(
            $divisions,
            fn (int $division): bool => $this->isVisibleTo($division, $viewer),
        ));
        sort($visibleDivisions);

        return $visibleDivisions;
    }

    public function getConfiguredBy(): ?User
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
