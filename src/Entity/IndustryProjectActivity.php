<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProjectActivityKind;
use App\Repository\IndustryProjectActivityRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectActivityRepository::class)]
#[ORM\Table(name: 'industry_project_activities')]
#[ORM\Index(columns: ['project_id', 'occurred_at'])]
class IndustryProjectActivity
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\Column(type: 'string', length: 40, enumType: ProjectActivityKind::class)]
    private ProjectActivityKind $kind;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $data = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
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

    public function getKind(): ProjectActivityKind
    {
        return $this->kind;
    }

    public function setKind(ProjectActivityKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }
}
