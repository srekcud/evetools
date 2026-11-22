<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UnassignedJobRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UnassignedJobRepository::class)]
#[ORM\Table(name: 'industry_unassigned_jobs')]
class UnassignedJob
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: CachedIndustryJob::class)]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private CachedIndustryJob $job;

    /** @var list<array{projectId: string, lineId: string, score: int, signals: list<string>, oversized: bool}> */
    #[ORM\Column(type: 'json')]
    private array $candidates = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $detectedAt;

    public function __construct()
    {
        $this->detectedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getJob(): CachedIndustryJob
    {
        return $this->job;
    }

    public function setJob(CachedIndustryJob $job): static
    {
        $this->job = $job;

        return $this;
    }

    /**
     * @return list<array{projectId: string, lineId: string, score: int, signals: list<string>, oversized: bool}>
     */
    public function getCandidates(): array
    {
        return $this->candidates;
    }

    /**
     * @param list<array{projectId: string, lineId: string, score: int, signals: list<string>, oversized: bool}> $candidates
     */
    public function setCandidates(array $candidates): static
    {
        $this->candidates = $candidates;

        return $this;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }
}
