<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanetaryRouteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PlanetaryRouteRepository::class)]
#[ORM\Table(name: 'planetary_routes')]
#[ORM\UniqueConstraint(columns: ['colony_id', 'route_id'])]
#[ORM\Index(columns: ['colony_id'])]
class PlanetaryRoute
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: PlanetaryColony::class, inversedBy: 'routes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PlanetaryColony $colony;

    #[ORM\Column(type: 'integer')]
    private int $routeId;

    #[ORM\Column(type: 'bigint')]
    private int $sourcePinId;

    #[ORM\Column(type: 'bigint')]
    private int $destinationPinId;

    #[ORM\Column(type: 'integer')]
    private int $contentTypeId;

    #[ORM\Column(type: 'float')]
    private float $quantity;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $waypoints = null;

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

    public function getRouteId(): int
    {
        return $this->routeId;
    }

    public function setRouteId(int $routeId): static
    {
        $this->routeId = $routeId;
        return $this;
    }

    public function getSourcePinId(): int
    {
        return $this->sourcePinId;
    }

    public function setSourcePinId(int $sourcePinId): static
    {
        $this->sourcePinId = $sourcePinId;
        return $this;
    }

    public function getDestinationPinId(): int
    {
        return $this->destinationPinId;
    }

    public function setDestinationPinId(int $destinationPinId): static
    {
        $this->destinationPinId = $destinationPinId;
        return $this;
    }

    public function getContentTypeId(): int
    {
        return $this->contentTypeId;
    }

    public function setContentTypeId(int $contentTypeId): static
    {
        $this->contentTypeId = $contentTypeId;
        return $this;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function setQuantity(float $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getWaypoints(): ?array
    {
        return $this->waypoints;
    }

    public function setWaypoints(?array $waypoints): static
    {
        $this->waypoints = $waypoints;
        return $this;
    }
}
