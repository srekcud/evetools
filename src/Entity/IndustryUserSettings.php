<?php

declare(strict_types=1);

namespace App\Entity;

use App\Constant\EveConstants;
use App\Repository\IndustryUserSettingsRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryUserSettingsRepository::class)]
#[ORM\Table(name: 'industry_user_settings')]
#[ORM\UniqueConstraint(columns: ['user_id'])]
class IndustryUserSettings
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Favorite solar system ID for manufacturing */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $favoriteManufacturingSystemId = null;

    /** Favorite solar system ID for reactions */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $favoriteReactionSystemId = null;

    /** Broker fee rate (default 3.6% = skills level 0) */
    #[ORM\Column(type: 'float', options: ['default' => 0.036])]
    private float $brokerFeeRate = EveConstants::DEFAULT_BROKER_FEE_RATE;

    /** Sales tax rate (default 3.6% = skills level 0) */
    #[ORM\Column(type: 'float', options: ['default' => 0.036])]
    private float $salesTaxRate = EveConstants::DEFAULT_SALES_TAX_RATE;

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

    public function getFavoriteManufacturingSystemId(): ?int
    {
        return $this->favoriteManufacturingSystemId;
    }

    public function setFavoriteManufacturingSystemId(?int $id): static
    {
        $this->favoriteManufacturingSystemId = $id;
        return $this;
    }

    public function getFavoriteReactionSystemId(): ?int
    {
        return $this->favoriteReactionSystemId;
    }

    public function setFavoriteReactionSystemId(?int $id): static
    {
        $this->favoriteReactionSystemId = $id;
        return $this;
    }

    public function getBrokerFeeRate(): float
    {
        return $this->brokerFeeRate;
    }

    public function setBrokerFeeRate(float $brokerFeeRate): static
    {
        $this->brokerFeeRate = $brokerFeeRate;
        return $this;
    }

    public function getSalesTaxRate(): float
    {
        return $this->salesTaxRate;
    }

    public function setSalesTaxRate(float $salesTaxRate): static
    {
        $this->salesTaxRate = $salesTaxRate;
        return $this;
    }
}
