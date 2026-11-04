<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MoonTaxSyncFailure;
use App\Repository\MoonTaxSyncStateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MoonTaxSyncStateRepository::class)]
#[ORM\Table(name: 'moon_tax_sync_states')]
class MoonTaxSyncState
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint', unique: true)]
    private int $corporationId;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $observersSyncedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $walletSyncedAt = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true, enumType: MoonTaxSyncFailure::class)]
    private ?MoonTaxSyncFailure $observersFailure = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true, enumType: MoonTaxSyncFailure::class)]
    private ?MoonTaxSyncFailure $walletFailure = null;

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

    public function getObserversSyncedAt(): ?\DateTimeImmutable
    {
        return $this->observersSyncedAt;
    }

    public function setObserversSyncedAt(?\DateTimeImmutable $observersSyncedAt): static
    {
        $this->observersSyncedAt = $observersSyncedAt;

        return $this;
    }

    public function getWalletSyncedAt(): ?\DateTimeImmutable
    {
        return $this->walletSyncedAt;
    }

    public function setWalletSyncedAt(?\DateTimeImmutable $walletSyncedAt): static
    {
        $this->walletSyncedAt = $walletSyncedAt;

        return $this;
    }

    public function getObserversFailure(): ?MoonTaxSyncFailure
    {
        return $this->observersFailure;
    }

    public function setObserversFailure(?MoonTaxSyncFailure $observersFailure): static
    {
        $this->observersFailure = $observersFailure;

        return $this;
    }

    public function getWalletFailure(): ?MoonTaxSyncFailure
    {
        return $this->walletFailure;
    }

    public function setWalletFailure(?MoonTaxSyncFailure $walletFailure): static
    {
        $this->walletFailure = $walletFailure;

        return $this;
    }
}
