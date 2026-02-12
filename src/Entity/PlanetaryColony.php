<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanetaryColonyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlanetaryColonyRepository::class)]
#[ORM\Table(name: 'planetary_colonies')]
#[ORM\UniqueConstraint(columns: ['character_id', 'planet_id'])]
#[ORM\Index(columns: ['character_id'])]
class PlanetaryColony
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: Character::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Character $character;

    #[ORM\Column(type: 'integer')]
    private int $planetId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $planetType;

    #[ORM\Column(type: 'integer')]
    private int $solarSystemId;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $solarSystemName = null;

    #[ORM\Column(type: 'smallint')]
    private int $upgradeLevel;

    #[ORM\Column(type: 'smallint')]
    private int $numPins;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastUpdate;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $cachedAt;

    /** @var Collection<int, PlanetaryPin> */
    #[ORM\OneToMany(targetEntity: PlanetaryPin::class, mappedBy: 'colony', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $pins;

    /** @var Collection<int, PlanetaryRoute> */
    #[ORM\OneToMany(targetEntity: PlanetaryRoute::class, mappedBy: 'colony', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $routes;

    public function __construct()
    {
        $this->pins = new ArrayCollection();
        $this->routes = new ArrayCollection();
        $this->cachedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function setCharacter(Character $character): static
    {
        $this->character = $character;
        return $this;
    }

    public function getPlanetId(): int
    {
        return $this->planetId;
    }

    public function setPlanetId(int $planetId): static
    {
        $this->planetId = $planetId;
        return $this;
    }

    public function getPlanetType(): string
    {
        return $this->planetType;
    }

    public function setPlanetType(string $planetType): static
    {
        $this->planetType = $planetType;
        return $this;
    }

    public function getSolarSystemId(): int
    {
        return $this->solarSystemId;
    }

    public function setSolarSystemId(int $solarSystemId): static
    {
        $this->solarSystemId = $solarSystemId;
        return $this;
    }

    public function getSolarSystemName(): ?string
    {
        return $this->solarSystemName;
    }

    public function setSolarSystemName(?string $solarSystemName): static
    {
        $this->solarSystemName = $solarSystemName;
        return $this;
    }

    public function getUpgradeLevel(): int
    {
        return $this->upgradeLevel;
    }

    public function setUpgradeLevel(int $upgradeLevel): static
    {
        $this->upgradeLevel = $upgradeLevel;
        return $this;
    }

    public function getNumPins(): int
    {
        return $this->numPins;
    }

    public function setNumPins(int $numPins): static
    {
        $this->numPins = $numPins;
        return $this;
    }

    public function getLastUpdate(): \DateTimeImmutable
    {
        return $this->lastUpdate;
    }

    public function setLastUpdate(\DateTimeImmutable $lastUpdate): static
    {
        $this->lastUpdate = $lastUpdate;
        return $this;
    }

    public function getCachedAt(): \DateTimeImmutable
    {
        return $this->cachedAt;
    }

    public function setCachedAt(\DateTimeImmutable $cachedAt): static
    {
        $this->cachedAt = $cachedAt;
        return $this;
    }

    /** @return Collection<int, PlanetaryPin> */
    public function getPins(): Collection
    {
        return $this->pins;
    }

    public function addPin(PlanetaryPin $pin): static
    {
        if (!$this->pins->contains($pin)) {
            $this->pins->add($pin);
            $pin->setColony($this);
        }
        return $this;
    }

    public function clearPins(): static
    {
        $this->pins->clear();
        return $this;
    }

    /** @return Collection<int, PlanetaryRoute> */
    public function getRoutes(): Collection
    {
        return $this->routes;
    }

    public function addRoute(PlanetaryRoute $route): static
    {
        if (!$this->routes->contains($route)) {
            $this->routes->add($route);
            $route->setColony($this);
        }
        return $this;
    }

    public function clearRoutes(): static
    {
        $this->routes->clear();
        return $this;
    }
}
