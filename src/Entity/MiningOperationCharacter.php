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

    public function __construct(
        #[ORM\ManyToOne(targetEntity: MiningOperationMember::class, inversedBy: 'characters')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private MiningOperationMember $member,
        #[ORM\ManyToOne(targetEntity: Character::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Character $character,
    ) {
        $this->enrolledAt = new \DateTimeImmutable();
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

    /**
     * @return Collection<int, MiningLedgerSnapshot>
     */
    public function getSnapshots(): Collection
    {
        return new ArrayCollection();
    }

    public function snapshotOf(MiningLedgerSnapshotKind $kind): ?MiningLedgerSnapshot
    {
        return null;
    }

    public function scheduleStartSnapshot(\DateTimeImmutable $referenceAt): MiningLedgerSnapshot
    {
        return new MiningLedgerSnapshot($this, MiningLedgerSnapshotKind::Start, $referenceAt);
    }

    public function scheduleEndSnapshot(\DateTimeImmutable $referenceAt): MiningLedgerSnapshot
    {
        return new MiningLedgerSnapshot($this, MiningLedgerSnapshotKind::End, $referenceAt);
    }

    /**
     * @return Collection<int, MiningQuantityCorrection>
     */
    public function getCorrections(): Collection
    {
        return new ArrayCollection();
    }

    public function correct(int $typeId, ?int $originalQuantity, int $correctedQuantity, ?User $correctedBy, \DateTimeImmutable $correctedAt): MiningQuantityCorrection
    {
        return new MiningQuantityCorrection($this, $typeId, $originalQuantity, $correctedQuantity, $correctedBy, null, $correctedAt);
    }
}
