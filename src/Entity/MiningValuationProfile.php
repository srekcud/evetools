<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MarketSide;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningValuationProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MiningValuationProfileRepository::class)]
#[ORM\Table(name: 'user_mining_valuation_profiles')]
class MiningValuationProfile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private User $user;

    #[ORM\Column(type: 'string', length: 20, enumType: MiningValuationMarket::class, options: ['default' => 'jita'])]
    private MiningValuationMarket $market = MiningValuationMarket::Jita;

    #[ORM\Column(type: 'string', length: 10, enumType: MarketSide::class, options: ['default' => 'buy'])]
    private MarketSide $side = MarketSide::Buy;

    #[ORM\Column(type: 'string', length: 20, enumType: MiningValuationForm::class, options: ['default' => 'compressed'])]
    private MiningValuationForm $form = MiningValuationForm::Compressed;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $structureId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $structureName = null;

    #[ORM\Column(type: 'float', options: ['default' => 0])]
    private float $exportTax = 0.0;

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

    public function getMarket(): MiningValuationMarket
    {
        return $this->market;
    }

    public function setMarket(MiningValuationMarket $market): static
    {
        $this->market = $market;

        return $this;
    }

    public function getSide(): MarketSide
    {
        return $this->side;
    }

    public function setSide(MarketSide $side): static
    {
        $this->side = $side;

        return $this;
    }

    public function getForm(): MiningValuationForm
    {
        return $this->form;
    }

    public function setForm(MiningValuationForm $form): static
    {
        $this->form = $form;

        return $this;
    }

    public function getStructureId(): ?int
    {
        return $this->structureId;
    }

    public function setStructureId(?int $structureId): static
    {
        $this->structureId = $structureId;

        return $this;
    }

    public function getStructureName(): ?string
    {
        return $this->structureName;
    }

    public function setStructureName(?string $structureName): static
    {
        $this->structureName = $structureName;

        return $this;
    }

    public function getExportTax(): float
    {
        return $this->exportTax;
    }

    public function setExportTax(float $exportTax): static
    {
        $this->exportTax = $exportTax;

        return $this;
    }
}
