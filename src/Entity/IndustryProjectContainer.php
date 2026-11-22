<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'industry_project_containers')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\UniqueConstraint(columns: ['project_id', 'name'])]
#[ORM\UniqueConstraint(columns: ['project_id', 'location_id'])]
class IndustryProjectContainer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class, inversedBy: 'containers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $locationId = null;

    public function __construct(IndustryProject $project, string $name)
    {
        $this->id = Uuid::v7();
        $this->project = $project;
        $this->name = $name;
    }

    public static function keyOf(string $containerName): string
    {
        return mb_strtolower(trim($containerName));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): IndustryProject
    {
        return $this->project;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLocationId(): ?int
    {
        return $this->locationId;
    }

    public function locateAt(int $locationId): static
    {
        $this->locationId = $locationId;

        return $this;
    }
}
