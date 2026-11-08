<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectCourierContractRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectCourierContractRepository::class)]
#[ORM\Table(name: 'industry_project_courier_contracts')]
#[ORM\UniqueConstraint(columns: ['project_id'])]
class IndustryProjectCourierContract
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: IndustryProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\Column(type: 'bigint')]
    private int $contractId;

    #[ORM\Column(type: 'bigint')]
    private int $characterId;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $linkedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $failureNotifiedAt = null;

    public function __construct()
    {
        $this->linkedAt = new \DateTimeImmutable();
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

    public function getContractId(): int
    {
        return $this->contractId;
    }

    public function setContractId(int $contractId): static
    {
        $this->contractId = $contractId;

        return $this;
    }

    public function getCharacterId(): int
    {
        return $this->characterId;
    }

    public function setCharacterId(int $characterId): static
    {
        $this->characterId = $characterId;

        return $this;
    }

    public function getLinkedAt(): \DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function setLinkedAt(\DateTimeImmutable $linkedAt): static
    {
        $this->linkedAt = $linkedAt;

        return $this;
    }

    public function getFailureNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->failureNotifiedAt;
    }

    public function markFailureNotified(\DateTimeImmutable $notifiedAt): static
    {
        $this->failureNotifiedAt = $notifiedAt;

        return $this;
    }

    public function link(int $contractId, int $characterId, \DateTimeImmutable $linkedAt): static
    {
        $this->contractId = $contractId;
        $this->characterId = $characterId;
        $this->linkedAt = $linkedAt;
        $this->failureNotifiedAt = null;

        return $this;
    }
}
