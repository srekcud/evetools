<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectLineReservationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectLineReservationRepository::class)]
#[ORM\Table(name: 'industry_project_line_reservations')]
#[ORM\UniqueConstraint(columns: ['bom_line_id', 'participant_id'])]
#[ORM\Index(columns: ['participant_id'])]
class IndustryProjectLineReservation
{
    public const int LIFETIME_IN_HOURS = 48;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProjectBomLine::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectBomLine $bomLine;

    #[ORM\ManyToOne(targetEntity: IndustryProjectParticipant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectParticipant $participant;

    #[ORM\Column(type: 'integer')]
    private int $quantity;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $reservedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    public function __construct(IndustryProjectBomLine $bomLine, IndustryProjectParticipant $participant, int $quantity, ?\DateTimeImmutable $reservedAt = null)
    {
        $this->id = Uuid::v7();
        $this->bomLine = $bomLine;
        $this->participant = $participant;
        $this->quantity = $quantity;
        $this->startLifetimeAt($reservedAt ?? new \DateTimeImmutable());
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBomLine(): IndustryProjectBomLine
    {
        return $this->bomLine;
    }

    public function getParticipant(): IndustryProjectParticipant
    {
        return $this->participant;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getReservedAt(): \DateTimeImmutable
    {
        return $this->reservedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpiredAt(\DateTimeImmutable $moment): bool
    {
        return $this->expiresAt <= $moment;
    }

    public function renew(): void
    {
        $this->startLifetimeAt(new \DateTimeImmutable());
    }

    private function startLifetimeAt(\DateTimeImmutable $reservedAt): void
    {
        $this->reservedAt = $reservedAt;
        $this->expiresAt = $reservedAt->modify(sprintf('+%d hours', self::LIFETIME_IN_HOURS));
    }
}
