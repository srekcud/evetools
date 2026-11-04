<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MoonCompositionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MoonCompositionRepository::class)]
#[ORM\Table(name: 'moon_compositions')]
#[ORM\UniqueConstraint(name: 'uniq_moon_composition_corporation_moon', columns: ['corporation_id', 'moon_id'])]
class MoonComposition
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    #[ORM\Column(type: 'bigint')]
    private int $moonId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recordedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $recordedAt;

    /** @var list<array{typeId: int, share: float}> */
    #[ORM\Column(type: 'json')]
    private array $lines = [];

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

    public function getMoonId(): int
    {
        return $this->moonId;
    }

    public function setMoonId(int $moonId): static
    {
        $this->moonId = $moonId;

        return $this;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    public function setRecordedBy(?User $recordedBy): static
    {
        $this->recordedBy = $recordedBy;

        return $this;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(\DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;

        return $this;
    }

    /**
     * @return list<array{typeId: int, share: float}>
     */
    public function getLines(): array
    {
        return $this->lines;
    }

    /**
     * @param list<array{typeId: int, share: float}> $lines
     */
    public function setLines(array $lines): static
    {
        $this->lines = $lines;

        return $this;
    }
}
