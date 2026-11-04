<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'mining_site_scans')]
#[ORM\UniqueConstraint(columns: ['site_id', 'scanned_at'])]
class MiningSiteScan
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: MiningSite::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MiningSite $site;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $scannedAt;

    #[ORM\Column(type: 'bigint')]
    private int $totalUnits = 0;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $totalVolumeM3 = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $remainingValue = null;

    /** @var list<int> */
    #[ORM\Column(type: 'json')]
    private array $missingPriceTypeIds = [];

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $recentEta = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trendEta = null;

    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $types = [];

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSite(): MiningSite
    {
        return $this->site;
    }

    public function setSite(MiningSite $site): static
    {
        $this->site = $site;

        return $this;
    }

    public function getScannedAt(): \DateTimeImmutable
    {
        return $this->scannedAt;
    }

    public function setScannedAt(\DateTimeImmutable $scannedAt): static
    {
        $this->scannedAt = $scannedAt;

        return $this;
    }

    public function getTotalUnits(): int
    {
        return $this->totalUnits;
    }

    public function setTotalUnits(int $totalUnits): static
    {
        $this->totalUnits = $totalUnits;

        return $this;
    }

    public function getTotalVolumeM3(): ?float
    {
        return $this->totalVolumeM3;
    }

    public function setTotalVolumeM3(?float $totalVolumeM3): static
    {
        $this->totalVolumeM3 = $totalVolumeM3;

        return $this;
    }

    public function getRemainingValue(): ?float
    {
        return $this->remainingValue;
    }

    public function setRemainingValue(?float $remainingValue): static
    {
        $this->remainingValue = $remainingValue;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getMissingPriceTypeIds(): array
    {
        return $this->missingPriceTypeIds;
    }

    /**
     * @param list<int> $missingPriceTypeIds
     */
    public function setMissingPriceTypeIds(array $missingPriceTypeIds): static
    {
        $this->missingPriceTypeIds = $missingPriceTypeIds;

        return $this;
    }

    public function getRecentEta(): ?\DateTimeImmutable
    {
        return $this->recentEta;
    }

    public function setRecentEta(?\DateTimeImmutable $recentEta): static
    {
        $this->recentEta = $recentEta;

        return $this;
    }

    public function getTrendEta(): ?\DateTimeImmutable
    {
        return $this->trendEta;
    }

    public function setTrendEta(?\DateTimeImmutable $trendEta): static
    {
        $this->trendEta = $trendEta;

        return $this;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTypes(): array
    {
        return $this->types;
    }

    /**
     * @param list<array<string, mixed>> $types
     */
    public function setTypes(array $types): static
    {
        $this->types = $types;

        return $this;
    }
}
