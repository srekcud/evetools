<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Service\MiningOperation\MiningLedgerReading;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

class MiningLedgerSnapshot
{
    private ?Uuid $id = null;

    private MiningLedgerSnapshotStatus $status = MiningLedgerSnapshotStatus::Scheduling;

    private ?\DateTimeImmutable $readyAt = null;

    private ?\DateTimeImmutable $takenAt = null;

    private ?\DateTimeImmutable $esiExpiresAt = null;

    private ?string $failureReason = null;

    /** @var Collection<int, MiningLedgerSnapshotLine> */
    private Collection $lines;

    public function __construct(
        private MiningOperationCharacter $operationCharacter,
        private MiningLedgerSnapshotKind $kind,
        private \DateTimeImmutable $referenceAt,
    ) {
        $this->lines = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperationCharacter(): MiningOperationCharacter
    {
        return $this->operationCharacter;
    }

    public function getKind(): MiningLedgerSnapshotKind
    {
        return $this->kind;
    }

    public function getStatus(): MiningLedgerSnapshotStatus
    {
        return $this->status;
    }

    public function getReferenceAt(): \DateTimeImmutable
    {
        return $this->referenceAt;
    }

    public function getReadyAt(): ?\DateTimeImmutable
    {
        return $this->readyAt;
    }

    public function getTakenAt(): ?\DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function getEsiExpiresAt(): ?\DateTimeImmutable
    {
        return $this->esiExpiresAt;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /**
     * @return Collection<int, MiningLedgerSnapshotLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function schedule(\DateTimeImmutable $readyAt): void
    {
    }

    public function take(\DateTimeImmutable $takenAt, MiningLedgerReading $reading): void
    {
    }

    public function fail(MiningLedgerSnapshotStatus $status, ?string $failureReason = null): void
    {
    }
}
