<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProfitSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ProfitSettingsRepository::class)]
#[ORM\Table(name: 'profit_settings')]
class ProfitSettings
{
    public const COST_SOURCE_MARKET = 'market';
    public const COST_SOURCE_PROJECT = 'project';
    public const COST_SOURCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'float')]
    private float $salesTaxRate = 0.036;

    #[ORM\Column(type: 'string', length: 20)]
    private string $defaultCostSource = self::COST_SOURCE_MARKET;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getSalesTaxRate(): float
    {
        return $this->salesTaxRate;
    }

    public function setSalesTaxRate(float $salesTaxRate): static
    {
        $this->salesTaxRate = $salesTaxRate;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getDefaultCostSource(): string
    {
        return $this->defaultCostSource;
    }

    public function setDefaultCostSource(string $defaultCostSource): static
    {
        if (!in_array($defaultCostSource, [self::COST_SOURCE_MARKET, self::COST_SOURCE_PROJECT, self::COST_SOURCE_MANUAL], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid defaultCostSource value: %s. Must be "%s", "%s", or "%s".',
                $defaultCostSource,
                self::COST_SOURCE_MARKET,
                self::COST_SOURCE_PROJECT,
                self::COST_SOURCE_MANUAL
            ));
        }

        $this->defaultCostSource = $defaultCostSource;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
