<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryStockpileImportRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryStockpileImportRepository::class)]
#[ORM\Table(name: 'industry_stockpile_imports')]
class IndustryStockpileImport
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column(type: 'integer')]
        private int $productTypeId,
        #[ORM\Column(type: 'integer')]
        private int $runs,
        #[ORM\Column(type: 'integer')]
        private int $me,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getProductTypeId(): int
    {
        return $this->productTypeId;
    }

    public function getRuns(): int
    {
        return $this->runs;
    }

    public function getMe(): int
    {
        return $this->me;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
