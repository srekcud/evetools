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

    public function __construct(IndustryProjectBomLine $bomLine, IndustryProjectParticipant $participant, int $quantity)
    {
        $this->id = Uuid::v7();
        $this->bomLine = $bomLine;
        $this->participant = $participant;
        $this->quantity = $quantity;
        $this->reservedAt = new \DateTimeImmutable();
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
}
