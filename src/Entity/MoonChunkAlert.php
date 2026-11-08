<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MoonChunkAlertKind;
use App\Repository\MoonChunkAlertRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MoonChunkAlertRepository::class)]
#[ORM\Table(name: 'moon_chunk_alerts')]
#[ORM\UniqueConstraint(name: 'uniq_moon_chunk_alert_user_extraction_kind', columns: ['user_id', 'extraction_id', 'kind'])]
class MoonChunkAlert
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: CorporationMiningExtraction::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CorporationMiningExtraction $extraction;

    #[ORM\Column(type: 'string', length: 16, enumType: MoonChunkAlertKind::class)]
    private MoonChunkAlertKind $kind;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $sentAt;

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

    public function getExtraction(): CorporationMiningExtraction
    {
        return $this->extraction;
    }

    public function setExtraction(CorporationMiningExtraction $extraction): static
    {
        $this->extraction = $extraction;

        return $this;
    }

    public function getKind(): MoonChunkAlertKind
    {
        return $this->kind;
    }

    public function setKind(MoonChunkAlertKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getSentAt(): \DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(\DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;

        return $this;
    }
}
