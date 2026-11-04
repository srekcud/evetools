<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MoonOreRarity;
use App\Enum\MoonTaxPeriodKind;
use App\Repository\MoonTaxRefinerySettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MoonTaxRefinerySettingRepository::class)]
#[ORM\Table(name: 'moon_tax_refinery_settings')]
#[ORM\UniqueConstraint(columns: ['corporation_id', 'structure_id'])]
class MoonTaxRefinerySetting
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'bigint')]
    private int $corporationId;

    #[ORM\Column(type: 'bigint')]
    private int $structureId;

    #[ORM\Column(type: 'boolean')]
    private bool $openToAlliance = false;

    #[ORM\Column(type: 'string', length: 10, enumType: MoonTaxPeriodKind::class)]
    private MoonTaxPeriodKind $period = MoonTaxPeriodKind::Weekly;

    #[ORM\Column(type: 'float')]
    private float $rateR4 = 0.0;

    #[ORM\Column(type: 'float')]
    private float $rateR8 = 0.0;

    #[ORM\Column(type: 'float')]
    private float $rateR16 = 0.0;

    #[ORM\Column(type: 'float')]
    private float $rateR32 = 0.0;

    #[ORM\Column(type: 'float')]
    private float $rateR64 = 0.0;

    #[ORM\Column(type: 'smallint')]
    private int $walletDivision = 1;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCorporationId(): int
    {
        return $this->corporationId;
    }

    public function setCorporationId(int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
    }

    public function getStructureId(): int
    {
        return $this->structureId;
    }

    public function setStructureId(int $structureId): static
    {
        $this->structureId = $structureId;

        return $this;
    }

    public function isOpenToAlliance(): bool
    {
        return $this->openToAlliance;
    }

    public function setOpenToAlliance(bool $openToAlliance): static
    {
        $this->openToAlliance = $openToAlliance;

        return $this;
    }

    public function getPeriod(): MoonTaxPeriodKind
    {
        return $this->period;
    }

    public function setPeriod(MoonTaxPeriodKind $period): static
    {
        $this->period = $period;

        return $this;
    }

    public function getRateR4(): float
    {
        return $this->rateR4;
    }

    public function setRateR4(float $rateR4): static
    {
        $this->rateR4 = $rateR4;

        return $this;
    }

    public function getRateR8(): float
    {
        return $this->rateR8;
    }

    public function setRateR8(float $rateR8): static
    {
        $this->rateR8 = $rateR8;

        return $this;
    }

    public function getRateR16(): float
    {
        return $this->rateR16;
    }

    public function setRateR16(float $rateR16): static
    {
        $this->rateR16 = $rateR16;

        return $this;
    }

    public function getRateR32(): float
    {
        return $this->rateR32;
    }

    public function setRateR32(float $rateR32): static
    {
        $this->rateR32 = $rateR32;

        return $this;
    }

    public function getRateR64(): float
    {
        return $this->rateR64;
    }

    public function setRateR64(float $rateR64): static
    {
        $this->rateR64 = $rateR64;

        return $this;
    }

    public function getWalletDivision(): int
    {
        return $this->walletDivision;
    }

    public function setWalletDivision(int $walletDivision): static
    {
        $this->walletDivision = $walletDivision;

        return $this;
    }

    /**
     * @return array<string, float> keyed by MoonOreRarity::value
     */
    public function ratesPercentByRarity(): array
    {
        return [
            MoonOreRarity::R4->value => $this->rateR4,
            MoonOreRarity::R8->value => $this->rateR8,
            MoonOreRarity::R16->value => $this->rateR16,
            MoonOreRarity::R32->value => $this->rateR32,
            MoonOreRarity::R64->value => $this->rateR64,
        ];
    }
}
