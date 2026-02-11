<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryStepJobMatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryStepJobMatchRepository::class)]
#[ORM\Table(name: 'industry_step_job_matches')]
#[ORM\Index(columns: ['step_id'])]
#[ORM\Index(columns: ['esi_job_id'])]
class IndustryStepJobMatch
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: IndustryProjectStep::class, inversedBy: 'jobMatches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectStep $step;

    #[ORM\Column(type: 'integer')]
    private int $esiJobId;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $cost = null;

    #[ORM\Column(type: 'string', length: 30)]
    private string $status = 'active';

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(type: 'integer')]
    private int $runs;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $characterName = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $facilityId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $facilityName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $plannedStructureName = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $plannedMaterialBonus = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $matchedAt;

    public function __construct()
    {
        $this->matchedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getStep(): IndustryProjectStep
    {
        return $this->step;
    }

    public function setStep(IndustryProjectStep $step): static
    {
        $this->step = $step;
        return $this;
    }

    public function getEsiJobId(): int
    {
        return $this->esiJobId;
    }

    public function setEsiJobId(int $esiJobId): static
    {
        $this->esiJobId = $esiJobId;
        return $this;
    }

    public function getCost(): ?float
    {
        return $this->cost;
    }

    public function setCost(?float $cost): static
    {
        $this->cost = $cost;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function setRuns(int $runs): static
    {
        $this->runs = $runs;
        return $this;
    }

    public function getCharacterName(): ?string
    {
        return $this->characterName;
    }

    public function setCharacterName(?string $characterName): static
    {
        $this->characterName = $characterName;
        return $this;
    }

    public function getFacilityId(): ?int
    {
        return $this->facilityId;
    }

    public function setFacilityId(?int $facilityId): static
    {
        $this->facilityId = $facilityId;
        return $this;
    }

    public function getFacilityName(): ?string
    {
        return $this->facilityName;
    }

    public function setFacilityName(?string $facilityName): static
    {
        $this->facilityName = $facilityName;
        return $this;
    }

    public function getPlannedStructureName(): ?string
    {
        return $this->plannedStructureName;
    }

    public function setPlannedStructureName(?string $plannedStructureName): static
    {
        $this->plannedStructureName = $plannedStructureName;
        return $this;
    }

    public function getPlannedMaterialBonus(): ?float
    {
        return $this->plannedMaterialBonus;
    }

    public function setPlannedMaterialBonus(?float $plannedMaterialBonus): static
    {
        $this->plannedMaterialBonus = $plannedMaterialBonus;
        return $this;
    }

    public function getMatchedAt(): \DateTimeImmutable
    {
        return $this->matchedAt;
    }

    public function setMatchedAt(\DateTimeImmutable $matchedAt): static
    {
        $this->matchedAt = $matchedAt;
        return $this;
    }
}
