<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'mining_ledger_snapshot_lines')]
#[ORM\UniqueConstraint(columns: ['snapshot_id', 'date', 'type_id', 'solar_system_id'])]
class MiningLedgerSnapshotLine
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MiningLedgerSnapshot::class, inversedBy: 'lines')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MiningLedgerSnapshot $snapshot,
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $date,
        #[ORM\Column(type: 'integer')]
        private int $typeId,
        #[ORM\Column(type: 'integer')]
        private int $solarSystemId,
        #[ORM\Column(type: 'bigint')]
        private int $quantity,
    ) {
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getSnapshot(): MiningLedgerSnapshot
    {
        return $this->snapshot;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getSolarSystemId(): int
    {
        return $this->solarSystemId;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }
}
