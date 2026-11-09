<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectTransferReceiptRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectTransferReceiptRepository::class)]
#[ORM\Table(name: 'industry_project_transfer_receipts')]
#[ORM\UniqueConstraint(columns: ['project_id', 'from_participant_id', 'to_participant_id'])]
class IndustryProjectTransferReceipt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\ManyToOne(targetEntity: IndustryProjectParticipant::class)]
    #[ORM\JoinColumn(name: 'from_participant_id', nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectParticipant $fromParticipant;

    #[ORM\ManyToOne(targetEntity: IndustryProjectParticipant::class)]
    #[ORM\JoinColumn(name: 'to_participant_id', nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectParticipant $toParticipant;

    #[ORM\Column(type: 'bigint')]
    private int $amountCents;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getProject(): IndustryProject
    {
        return $this->project;
    }

    public function setProject(IndustryProject $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getFromParticipant(): IndustryProjectParticipant
    {
        return $this->fromParticipant;
    }

    public function setFromParticipant(IndustryProjectParticipant $fromParticipant): static
    {
        $this->fromParticipant = $fromParticipant;

        return $this;
    }

    public function getToParticipant(): IndustryProjectParticipant
    {
        return $this->toParticipant;
    }

    public function setToParticipant(IndustryProjectParticipant $toParticipant): static
    {
        $this->toParticipant = $toParticipant;

        return $this;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function setAmountCents(int $amountCents): static
    {
        $this->amountCents = $amountCents;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }
}
