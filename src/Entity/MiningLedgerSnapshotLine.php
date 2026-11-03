<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Uuid;

class MiningLedgerSnapshotLine
{
    private ?Uuid $id = null;

    public function __construct(
        private MiningLedgerSnapshot $snapshot,
        private \DateTimeImmutable $date,
        private int $typeId,
        private int $solarSystemId,
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
