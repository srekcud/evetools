<?php

declare(strict_types=1);

namespace App\Entity\Sde;

use App\Repository\Sde\DgmEffectRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DgmEffectRepository::class)]
#[ORM\Table(name: 'sde_dgm_effects')]
class DgmEffect
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $effectId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $effectName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $effectCategory = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $preExpression = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $postExpression = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $guid = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $iconId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isOffensive = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isAssistance = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationAttributeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $trackingSpeedAttributeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $dischargeAttributeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $rangeAttributeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $falloffAttributeId = null;

    #[ORM\Column(type: 'boolean')]
    private bool $disallowAutoRepeat = false;

    #[ORM\Column(type: 'boolean')]
    private bool $published = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isWarpSafe = false;

    #[ORM\Column(type: 'boolean')]
    private bool $rangeChance = false;

    #[ORM\Column(type: 'boolean')]
    private bool $electronicChance = false;

    #[ORM\Column(type: 'boolean')]
    private bool $propulsionChance = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $distribution = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $sfxName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $npcUsageChanceAttributeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $npcActivationChanceAttributeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $fittingUsageChanceAttributeId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $modifierInfo = null;

    public function getEffectId(): int
    {
        return $this->effectId;
    }

    public function setEffectId(int $effectId): self
    {
        $this->effectId = $effectId;
        return $this;
    }

    public function getEffectName(): ?string
    {
        return $this->effectName;
    }

    public function setEffectName(?string $effectName): self
    {
        $this->effectName = $effectName;
        return $this;
    }

    public function getEffectCategory(): ?int
    {
        return $this->effectCategory;
    }

    public function setEffectCategory(?int $effectCategory): self
    {
        $this->effectCategory = $effectCategory;
        return $this;
    }

    public function getPreExpression(): ?int
    {
        return $this->preExpression;
    }

    public function setPreExpression(?int $preExpression): self
    {
        $this->preExpression = $preExpression;
        return $this;
    }

    public function getPostExpression(): ?int
    {
        return $this->postExpression;
    }

    public function setPostExpression(?int $postExpression): self
    {
        $this->postExpression = $postExpression;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getGuid(): ?string
    {
        return $this->guid;
    }

    public function setGuid(?string $guid): self
    {
        $this->guid = $guid;
        return $this;
    }

    public function getIconId(): ?int
    {
        return $this->iconId;
    }

    public function setIconId(?int $iconId): self
    {
        $this->iconId = $iconId;
        return $this;
    }

    public function isOffensive(): bool
    {
        return $this->isOffensive;
    }

    public function setIsOffensive(bool $isOffensive): self
    {
        $this->isOffensive = $isOffensive;
        return $this;
    }

    public function isAssistance(): bool
    {
        return $this->isAssistance;
    }

    public function setIsAssistance(bool $isAssistance): self
    {
        $this->isAssistance = $isAssistance;
        return $this;
    }

    public function getDurationAttributeId(): ?int
    {
        return $this->durationAttributeId;
    }

    public function setDurationAttributeId(?int $durationAttributeId): self
    {
        $this->durationAttributeId = $durationAttributeId;
        return $this;
    }

    public function getTrackingSpeedAttributeId(): ?int
    {
        return $this->trackingSpeedAttributeId;
    }

    public function setTrackingSpeedAttributeId(?int $trackingSpeedAttributeId): self
    {
        $this->trackingSpeedAttributeId = $trackingSpeedAttributeId;
        return $this;
    }

    public function getDischargeAttributeId(): ?int
    {
        return $this->dischargeAttributeId;
    }

    public function setDischargeAttributeId(?int $dischargeAttributeId): self
    {
        $this->dischargeAttributeId = $dischargeAttributeId;
        return $this;
    }

    public function getRangeAttributeId(): ?int
    {
        return $this->rangeAttributeId;
    }

    public function setRangeAttributeId(?int $rangeAttributeId): self
    {
        $this->rangeAttributeId = $rangeAttributeId;
        return $this;
    }

    public function getFalloffAttributeId(): ?int
    {
        return $this->falloffAttributeId;
    }

    public function setFalloffAttributeId(?int $falloffAttributeId): self
    {
        $this->falloffAttributeId = $falloffAttributeId;
        return $this;
    }

    public function isDisallowAutoRepeat(): bool
    {
        return $this->disallowAutoRepeat;
    }

    public function setDisallowAutoRepeat(bool $disallowAutoRepeat): self
    {
        $this->disallowAutoRepeat = $disallowAutoRepeat;
        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(bool $published): self
    {
        $this->published = $published;
        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): self
    {
        $this->displayName = $displayName;
        return $this;
    }

    public function isWarpSafe(): bool
    {
        return $this->isWarpSafe;
    }

    public function setIsWarpSafe(bool $isWarpSafe): self
    {
        $this->isWarpSafe = $isWarpSafe;
        return $this;
    }

    public function isRangeChance(): bool
    {
        return $this->rangeChance;
    }

    public function setRangeChance(bool $rangeChance): self
    {
        $this->rangeChance = $rangeChance;
        return $this;
    }

    public function isElectronicChance(): bool
    {
        return $this->electronicChance;
    }

    public function setElectronicChance(bool $electronicChance): self
    {
        $this->electronicChance = $electronicChance;
        return $this;
    }

    public function isPropulsionChance(): bool
    {
        return $this->propulsionChance;
    }

    public function setPropulsionChance(bool $propulsionChance): self
    {
        $this->propulsionChance = $propulsionChance;
        return $this;
    }

    public function getDistribution(): ?int
    {
        return $this->distribution;
    }

    public function setDistribution(?int $distribution): self
    {
        $this->distribution = $distribution;
        return $this;
    }

    public function getSfxName(): ?string
    {
        return $this->sfxName;
    }

    public function setSfxName(?string $sfxName): self
    {
        $this->sfxName = $sfxName;
        return $this;
    }

    public function getNpcUsageChanceAttributeId(): ?int
    {
        return $this->npcUsageChanceAttributeId;
    }

    public function setNpcUsageChanceAttributeId(?int $npcUsageChanceAttributeId): self
    {
        $this->npcUsageChanceAttributeId = $npcUsageChanceAttributeId;
        return $this;
    }

    public function getNpcActivationChanceAttributeId(): ?int
    {
        return $this->npcActivationChanceAttributeId;
    }

    public function setNpcActivationChanceAttributeId(?int $npcActivationChanceAttributeId): self
    {
        $this->npcActivationChanceAttributeId = $npcActivationChanceAttributeId;
        return $this;
    }

    public function getFittingUsageChanceAttributeId(): ?int
    {
        return $this->fittingUsageChanceAttributeId;
    }

    public function setFittingUsageChanceAttributeId(?int $fittingUsageChanceAttributeId): self
    {
        $this->fittingUsageChanceAttributeId = $fittingUsageChanceAttributeId;
        return $this;
    }

    public function getModifierInfo(): ?string
    {
        return $this->modifierInfo;
    }

    public function setModifierInfo(?string $modifierInfo): self
    {
        $this->modifierInfo = $modifierInfo;
        return $this;
    }
}
