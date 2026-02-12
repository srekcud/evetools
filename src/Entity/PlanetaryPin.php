<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanetaryPinRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlanetaryPinRepository::class)]
#[ORM\Table(name: 'planetary_pins')]
#[ORM\UniqueConstraint(columns: ['colony_id', 'pin_id'])]
#[ORM\Index(columns: ['colony_id'])]
#[ORM\Index(columns: ['expiry_time'])]
class PlanetaryPin
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: PlanetaryColony::class, inversedBy: 'pins')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanetaryColony $colony;

    #[ORM\Column(type: 'bigint')]
    private int $pinId;

    #[ORM\Column(type: 'integer')]
    private int $typeId;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $typeName = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $schematicId = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $installTime = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiryTime = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastCycleStart = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $extractorProductTypeId = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $extractorCycleTime = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $extractorQtyPerCycle = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $extractorHeadRadius = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $extractorNumHeads = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $contents = null;

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getColony(): PlanetaryColony
    {
        return $this->colony;
    }

    public function setColony(PlanetaryColony $colony): static
    {
        $this->colony = $colony;
        return $this;
    }

    public function getPinId(): int
    {
        return $this->pinId;
    }

    public function setPinId(int $pinId): static
    {
        $this->pinId = $pinId;
        return $this;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function setTypeId(int $typeId): static
    {
        $this->typeId = $typeId;
        return $this;
    }

    public function getTypeName(): ?string
    {
        return $this->typeName;
    }

    public function setTypeName(?string $typeName): static
    {
        $this->typeName = $typeName;
        return $this;
    }

    public function getSchematicId(): ?int
    {
        return $this->schematicId;
    }

    public function setSchematicId(?int $schematicId): static
    {
        $this->schematicId = $schematicId;
        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    public function getInstallTime(): ?\DateTimeImmutable
    {
        return $this->installTime;
    }

    public function setInstallTime(?\DateTimeImmutable $installTime): static
    {
        $this->installTime = $installTime;
        return $this;
    }

    public function getExpiryTime(): ?\DateTimeImmutable
    {
        return $this->expiryTime;
    }

    public function setExpiryTime(?\DateTimeImmutable $expiryTime): static
    {
        $this->expiryTime = $expiryTime;
        return $this;
    }

    public function getLastCycleStart(): ?\DateTimeImmutable
    {
        return $this->lastCycleStart;
    }

    public function setLastCycleStart(?\DateTimeImmutable $lastCycleStart): static
    {
        $this->lastCycleStart = $lastCycleStart;
        return $this;
    }

    public function getExtractorProductTypeId(): ?int
    {
        return $this->extractorProductTypeId;
    }

    public function setExtractorProductTypeId(?int $extractorProductTypeId): static
    {
        $this->extractorProductTypeId = $extractorProductTypeId;
        return $this;
    }

    public function getExtractorCycleTime(): ?int
    {
        return $this->extractorCycleTime;
    }

    public function setExtractorCycleTime(?int $extractorCycleTime): static
    {
        $this->extractorCycleTime = $extractorCycleTime;
        return $this;
    }

    public function getExtractorQtyPerCycle(): ?int
    {
        return $this->extractorQtyPerCycle;
    }

    public function setExtractorQtyPerCycle(?int $extractorQtyPerCycle): static
    {
        $this->extractorQtyPerCycle = $extractorQtyPerCycle;
        return $this;
    }

    public function getExtractorHeadRadius(): ?float
    {
        return $this->extractorHeadRadius;
    }

    public function setExtractorHeadRadius(?float $extractorHeadRadius): static
    {
        $this->extractorHeadRadius = $extractorHeadRadius;
        return $this;
    }

    public function getExtractorNumHeads(): ?int
    {
        return $this->extractorNumHeads;
    }

    public function setExtractorNumHeads(?int $extractorNumHeads): static
    {
        $this->extractorNumHeads = $extractorNumHeads;
        return $this;
    }

    public function getContents(): ?array
    {
        return $this->contents;
    }

    public function setContents(?array $contents): static
    {
        $this->contents = $contents;
        return $this;
    }

    public function isExtractor(): bool
    {
        return $this->extractorProductTypeId !== null;
    }

    public function isFactory(): bool
    {
        return $this->schematicId !== null && !$this->isExtractor();
    }

    public function isExpired(): bool
    {
        return $this->expiryTime !== null && $this->expiryTime < new \DateTimeImmutable();
    }

    public function isExpiringSoon(int $hours = 24): bool
    {
        if ($this->expiryTime === null) {
            return false;
        }
        $threshold = new \DateTimeImmutable("+{$hours} hours");
        return !$this->isExpired() && $this->expiryTime < $threshold;
    }
}
