<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BomLineMarkState;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'industry_project_bom_line_marks')]
#[ORM\UniqueConstraint(columns: ['line_id'])]
class IndustryProjectBomLineMark
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProjectBomLine::class, inversedBy: 'marks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProjectBomLine $line;

    public function __construct(
        #[ORM\Column(type: 'string', length: 20, enumType: BomLineMarkState::class)]
        private BomLineMarkState $state,
        #[ORM\Column(type: 'integer')]
        private int $runs,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $markedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLine(): IndustryProjectBomLine
    {
        return $this->line;
    }

    public function setLine(IndustryProjectBomLine $line): static
    {
        $this->line = $line;

        return $this;
    }

    public function getState(): BomLineMarkState
    {
        return $this->state;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function getMarkedAt(): \DateTimeImmutable
    {
        return $this->markedAt;
    }

    public function replace(BomLineMarkState $state, int $runs, \DateTimeImmutable $markedAt): static
    {
        $this->state = $state;
        $this->runs = $runs;
        $this->markedAt = $markedAt;

        return $this;
    }
}
