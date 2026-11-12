<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'industry_project_declared_blueprint_copies')]
#[ORM\UniqueConstraint(columns: ['project_id', 'product_type_id'])]
class IndustryProjectDeclaredBlueprintCopy
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class, inversedBy: 'declaredBlueprintCopies')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    public function __construct(
        #[ORM\Column(type: 'integer')]
        private int $productTypeId,
        #[ORM\Column(type: 'integer')]
        private int $runs,
    ) {
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

    public function getProductTypeId(): int
    {
        return $this->productTypeId;
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
}
