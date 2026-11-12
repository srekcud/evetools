<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'sync_runs')]
#[ORM\Index(columns: ['type', 'started_at'])]
#[ORM\Index(columns: ['started_at'])]
class SyncRun
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', length: 60)]
    private string $type;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $status;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    private ?string $message = null;

    public function __construct(string $type, \DateTimeImmutable $startedAt, string $status)
    {
        $this->type = $type;
        $this->startedAt = $startedAt;
        $this->status = $status;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }
}
