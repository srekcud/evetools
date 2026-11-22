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

    /** Export cost per m3 for transporting finished products to sell venue (ISK/m3) */
    #[ORM\Column(type: 'float', options: ['default' => EveConstants::DEFAULT_EXPORT_COST_PER_M3])]
    private float $exportCostPerM3 = EveConstants::DEFAULT_EXPORT_COST_PER_M3;

    #[ORM\Column(type: 'integer', options: ['default' => EveConstants::DEFAULT_COURIER_HUB_SOLAR_SYSTEM_ID])]
    private int $courierHubSolarSystemId = EveConstants::DEFAULT_COURIER_HUB_SOLAR_SYSTEM_ID;

    #[ORM\Column(type: 'float', options: ['default' => EveConstants::DEFAULT_JUMP_BRIDGE_COST_PER_M3])]
    private float $jumpBridgeCostPerM3 = EveConstants::DEFAULT_JUMP_BRIDGE_COST_PER_M3;

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

    public function getExportCostPerM3(): float
    {
        return $this->exportCostPerM3;
    }

    public function setExportCostPerM3(float $exportCostPerM3): static
    {
        $this->exportCostPerM3 = $exportCostPerM3;
        return $this;
    }

    public function getCourierHubSolarSystemId(): int
    {
        return $this->courierHubSolarSystemId;
    }

    public function setCourierHubSolarSystemId(int $courierHubSolarSystemId): static
    {
        $this->courierHubSolarSystemId = $courierHubSolarSystemId;
        return $this;
    }

    public function getJumpBridgeCostPerM3(): float
    {
        return $this->jumpBridgeCostPerM3;
    }

    public function setJumpBridgeCostPerM3(float $jumpBridgeCostPerM3): static
    {
        $this->jumpBridgeCostPerM3 = $jumpBridgeCostPerM3;
        return $this;
    }
}
