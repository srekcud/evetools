<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningLedgerSnapshotKind;
use App\Enum\MiningLedgerSnapshotStatus;
use App\Repository\MiningLedgerSnapshotRepository;
use App\Service\MiningOperation\MiningLedgerReading;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MiningLedgerSnapshotRepository::class)]
#[ORM\Table(name: 'mining_ledger_snapshots')]
#[ORM\UniqueConstraint(columns: ['operation_character_id', 'kind'])]
class MiningLedgerSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'string', length: 20, enumType: MiningLedgerSnapshotStatus::class)]
    private MiningLedgerSnapshotStatus $status = MiningLedgerSnapshotStatus::Scheduling;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $takenAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $esiExpiresAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $failureReason = null;

    /** @var Collection<int, MiningLedgerSnapshotLine> */
    #[ORM\OneToMany(targetEntity: MiningLedgerSnapshotLine::class, mappedBy: 'snapshot', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MiningOperationCharacter::class, inversedBy: 'snapshots')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MiningOperationCharacter $operationCharacter,
        #[ORM\Column(type: 'string', length: 10, enumType: MiningLedgerSnapshotKind::class)]
        private MiningLedgerSnapshotKind $kind,
        #[ORM\Column(type: 'datetime_immutable')]
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
        $this->status = MiningLedgerSnapshotStatus::Waiting;
        $this->readyAt = $readyAt;
        $this->failureReason = null;
    }

    public function restartFrom(\DateTimeImmutable $referenceAt): void
    {
        $this->referenceAt = $referenceAt;
        $this->takenAt = null;
        $this->esiExpiresAt = null;
        $this->lines->clear();
        $this->schedule($referenceAt);
    }

    public function take(\DateTimeImmutable $takenAt, MiningLedgerReading $reading): void
    {
        $this->status = MiningLedgerSnapshotStatus::Taken;
        $this->takenAt = $takenAt;
        $this->esiExpiresAt = $reading->expiresAt;
        $this->failureReason = null;
        foreach ($reading->entries as $entry) {
            $this->lines->add(new MiningLedgerSnapshotLine($this, $entry->date, $entry->typeId, $entry->solarSystemId, $entry->quantity));
        }
    }

    public function fail(MiningLedgerSnapshotStatus $status, ?string $failureReason = null): void
    {
        $this->status = $status;
        $this->failureReason = $failureReason;
    }
}
