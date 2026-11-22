<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectLineBlueprintRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectLineBlueprintRepository::class)]
#[ORM\Table(name: 'industry_project_line_blueprints')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\Index(columns: ['bom_line_id'])]
#[ORM\UniqueConstraint(columns: ['project_id', 'item_id'])]
class IndustryProjectLineBlueprint
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\ManyToOne(targetEntity: IndustryProjectBomLine::class, inversedBy: 'blueprints')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectBomLine $bomLine;

    #[ORM\Column(type: 'bigint')]
    private int $itemId;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $attachedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $attachedAt;

    public function __construct(IndustryProjectBomLine $bomLine, int $itemId, User $attachedBy, \DateTimeImmutable $attachedAt)
    {
        $this->id = Uuid::v7();
        $this->project = $bomLine->getProject();
        $this->bomLine = $bomLine;
        $this->itemId = $itemId;
        $this->attachedBy = $attachedBy;
        $this->attachedAt = $attachedAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): IndustryProject
    {
        return $this->project;
    }

    public function getBomLine(): IndustryProjectBomLine
    {
        return $this->bomLine;
    }

    public function getItemId(): int
    {
        return $this->itemId;
    }

    public function getAttachedBy(): ?User
    {
        return $this->attachedBy;
    }

    public function getAttachedAt(): \DateTimeImmutable
    {
        return $this->attachedAt;
    }
}
