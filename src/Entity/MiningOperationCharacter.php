<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningLedgerSnapshotKind;
use App\Repository\MiningOperationCharacterRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MiningOperationCharacterRepository::class)]
#[ORM\Table(name: 'mining_operation_characters')]
#[ORM\Index(columns: ['character_id'])]
#[ORM\UniqueConstraint(columns: ['member_id', 'character_id'])]
class MiningOperationCharacter
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $enrolledAt;

    /** @var Collection<int, MiningLedgerSnapshot> */
    #[ORM\OneToMany(targetEntity: MiningLedgerSnapshot::class, mappedBy: 'operationCharacter', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $snapshots;

    /** @var Collection<int, MiningQuantityCorrection> */
    #[ORM\OneToMany(targetEntity: MiningQuantityCorrection::class, mappedBy: 'operationCharacter', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['correctedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $corrections;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $shipTypeId = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MiningOperationMember::class, inversedBy: 'characters')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MiningOperationMember $member,
        #[ORM\ManyToOne(targetEntity: Character::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Character $character,
    ) {
        $this->enrolledAt = new \DateTimeImmutable();
        $this->snapshots = new ArrayCollection();
        $this->corrections = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getMember(): MiningOperationMember
    {
        return $this->member;
    }

    public function getCharacter(): Character
    {
        return $this->character;
    }

    public function getEnrolledAt(): \DateTimeImmutable
    {
        return $this->enrolledAt;
    }

    public function getShipTypeId(): ?int
    {
        return $this->shipTypeId;
    }

    public function recordShip(?int $shipTypeId): void
    {
        $this->shipTypeId = $shipTypeId;
    }

    /**
     * @return Collection<int, MiningLedgerSnapshot>
     */
    public function getSnapshots(): Collection
    {
        return $this->snapshots;
    }

    public function snapshotOf(MiningLedgerSnapshotKind $kind): ?MiningLedgerSnapshot
    {
        foreach ($this->snapshots as $snapshot) {
            if ($snapshot->getKind() === $kind) {
                return $snapshot;
            }
        }

        return null;
    }

    public function scheduleStartSnapshot(\DateTimeImmutable $referenceAt): MiningLedgerSnapshot
    {
        $snapshot = new MiningLedgerSnapshot($this, MiningLedgerSnapshotKind::Start, $referenceAt);
        $snapshot->schedule($referenceAt);
        $this->snapshots->add($snapshot);

        return $snapshot;
    }

    public function scheduleEndSnapshot(\DateTimeImmutable $referenceAt): MiningLedgerSnapshot
    {
        $snapshot = new MiningLedgerSnapshot($this, MiningLedgerSnapshotKind::End, $referenceAt);
        $this->snapshots->add($snapshot);

        return $snapshot;
    }

    /**
     * @return Collection<int, MiningQuantityCorrection>
     */
    public function getCorrections(): Collection
    {
        return $this->corrections;
    }

    /**
     * @return array<int, MiningQuantityCorrection> latest correction of each type, by type id
     */
    public function latestCorrections(): array
    {
        $latest = [];
        foreach ($this->corrections as $correction) {
            $latest[$correction->getTypeId()] = $correction;
        }

        return $latest;
    }

    public function correct(int $typeId, ?int $originalQuantity, int $correctedQuantity, ?User $correctedBy, \DateTimeImmutable $correctedAt): MiningQuantityCorrection
    {
        $correction = new MiningQuantityCorrection(
            $this,
            $typeId,
            $originalQuantity,
            $correctedQuantity,
            $correctedBy,
            $correctedBy?->getMainCharacter()?->getName(),
            $correctedAt,
        );
        $this->corrections->add($correction);

        return $correction;
    }
}
