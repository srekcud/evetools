<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndustryProjectStationTransferRepository;
use App\Enum\TransferCarrierKind;
use App\Dto\Transfer\TransferEndpoint;
use App\Dto\Transfer\TransferLine;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectStationTransferRepository::class)]
#[ORM\Table(name: 'industry_project_station_transfers')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\UniqueConstraint(columns: ['route_id', 'leg_index'])]
class IndustryProjectStationTransfer
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $declaredBy;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $routeId;

    #[ORM\Column(type: 'integer')]
    private int $legIndex;

    #[ORM\Column(type: 'string', length: 20, enumType: TransferCarrierKind::class)]
    private TransferCarrierKind $carrierKind;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $carrierCharacterId;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $contractId;

    #[ORM\Column(type: 'integer')]
    private int $fromSolarSystemId;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $fromLocationId;

    #[ORM\Column(type: 'integer')]
    private int $toSolarSystemId;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $toLocationId;

    /**
     * @var list<array{typeId: int, quantity: int, departureBaseline: int|null, arrivalBaseline: int|null}>
     */
    #[ORM\Column(type: 'json')]
    private array $lines;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $declaredAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $arrivedAt = null;

    /**
     * @param list<TransferLine> $lines
     */
    public function __construct(
        IndustryProject $project,
        User $declaredBy,
        Uuid $routeId,
        int $legIndex,
        TransferCarrierKind $carrierKind,
        TransferEndpoint $from,
        TransferEndpoint $to,
        array $lines,
        \DateTimeImmutable $declaredAt,
        ?int $carrierCharacterId = null,
        ?int $contractId = null,
    ) {
        $this->id = Uuid::v7();
        $this->project = $project;
        $this->declaredBy = $declaredBy;
        $this->routeId = $routeId;
        $this->legIndex = $legIndex;
        $this->carrierKind = $carrierKind;
        $this->fromSolarSystemId = $from->solarSystemId;
        $this->fromLocationId = $from->locationId;
        $this->toSolarSystemId = $to->solarSystemId;
        $this->toLocationId = $to->locationId;
        $this->lines = array_map(static fn (TransferLine $line): array => $line->toArray(), $lines);
        $this->declaredAt = $declaredAt;
        $this->carrierCharacterId = $carrierCharacterId;
        $this->contractId = $contractId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): IndustryProject
    {
        return $this->project;
    }

    public function getDeclaredBy(): ?User
    {
        return $this->declaredBy;
    }

    public function getRouteId(): Uuid
    {
        return $this->routeId;
    }

    public function getLegIndex(): int
    {
        return $this->legIndex;
    }

    public function getCarrierKind(): TransferCarrierKind
    {
        return $this->carrierKind;
    }

    public function getCarrierCharacterId(): ?int
    {
        return $this->carrierCharacterId;
    }

    public function getContractId(): ?int
    {
        return $this->contractId;
    }

    public function getFrom(): TransferEndpoint
    {
        return new TransferEndpoint($this->fromSolarSystemId, $this->fromLocationId);
    }

    public function getTo(): TransferEndpoint
    {
        return new TransferEndpoint($this->toSolarSystemId, $this->toLocationId);
    }

    /**
     * @return list<TransferLine>
     */
    public function getLines(): array
    {
        return array_map(static fn (array $row): TransferLine => TransferLine::fromArray($row), $this->lines);
    }

    public function getDeclaredAt(): \DateTimeImmutable
    {
        return $this->declaredAt;
    }

    public function getArrivedAt(): ?\DateTimeImmutable
    {
        return $this->arrivedAt;
    }

    public function markArrived(\DateTimeImmutable $arrivedAt): static
    {
        $this->arrivedAt = $arrivedAt;

        return $this;
    }
}
