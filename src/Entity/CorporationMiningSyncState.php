<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CorporationMiningSyncFailure;
use App\Repository\CorporationMiningSyncStateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CorporationMiningSyncStateRepository::class)]
#[ORM\Table(name: 'corporation_mining_sync_states')]
class CorporationMiningSyncState
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint', unique: true)]
    private int $corporationId;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true, enumType: CorporationMiningSyncFailure::class)]
    private ?CorporationMiningSyncFailure $lastFailure = null;

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

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function setLastSuccessAt(?\DateTimeImmutable $lastSuccessAt): static
    {
        $this->lastSuccessAt = $lastSuccessAt;

        return $this;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function setLastAttemptAt(?\DateTimeImmutable $lastAttemptAt): static
    {
        $this->lastAttemptAt = $lastAttemptAt;

        return $this;
    }

    public function getLastFailure(): ?CorporationMiningSyncFailure
    {
        return $this->lastFailure;
    }

    public function setLastFailure(?CorporationMiningSyncFailure $lastFailure): static
    {
        $this->lastFailure = $lastFailure;

        return $this;
    }
}
