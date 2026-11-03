<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Sde\MapSolarSystem;
use App\Enum\MarketSide;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use App\Enum\MiningSplitMode;
use App\Enum\MiningValuationForm;
use App\Enum\MiningValuationMarket;
use App\Repository\MiningOperationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MiningOperationRepository::class)]
#[ORM\Table(name: 'mining_operations')]
#[ORM\Index(columns: ['owner_id'])]
#[ORM\Index(columns: ['visibility', 'corporation_id', 'status'])]
#[ORM\UniqueConstraint(columns: ['join_code'])]
class MiningOperation
{
    private const int JOIN_CODE_RANDOM_BYTES = 5;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(type: 'string', length: 100)]
    private string $label = '';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $plannedStartAt;

    #[ORM\Column(type: 'string', length: 20, enumType: MiningOperationStatus::class)]
    private MiningOperationStatus $status = MiningOperationStatus::Planned;

    #[ORM\Column(type: 'string', length: 20, enumType: MiningOperationVisibility::class)]
    private MiningOperationVisibility $visibility = MiningOperationVisibility::Private;

    #[ORM\Column(type: 'string', length: 30, enumType: MiningSplitMode::class)]
    private MiningSplitMode $splitMode = MiningSplitMode::ValueProrata;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?int $corporationId = null;

    #[ORM\Column(type: 'string', length: 10)]
    private string $joinCode;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $solarSystemId = null;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $solarSystemName = null;

    /** @var Collection<int, MiningOperationMember> */
    #[ORM\OneToMany(targetEntity: MiningOperationMember::class, mappedBy: 'operation', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    private MiningValuationForm $valuationForm = MiningValuationForm::Compressed;

    private MiningValuationMarket $valuationMarket = MiningValuationMarket::Jita;

    private MarketSide $valuationSide = MarketSide::Buy;

    private ?int $valuationStructureId = null;

    private ?string $valuationStructureName = null;

    private ?float $reprocessYield = null;

    private int $boosterCutPercent = 0;

    private int $corporationCutPercent = 0;

    private ?MiningOperationMember $boosterMember = null;

    private ?\DateTimeImmutable $sharesFrozenAt = null;

    private ?\DateTimeImmutable $pricesSyncedAt = null;

    /** @var Collection<int, MiningFrozenUnitPrice> */
    private Collection $frozenUnitPrices;

    /** @var Collection<int, MiningFrozenMinedValue> */
    private Collection $frozenMinedValues;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->frozenUnitPrices = new ArrayCollection();
        $this->frozenMinedValues = new ArrayCollection();
        $this->joinCode = self::generateJoinCode();
        $this->createdAt = new \DateTimeImmutable();
        $this->plannedStartAt = $this->createdAt;
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getPlannedStartAt(): \DateTimeImmutable
    {
        return $this->plannedStartAt;
    }

    public function setPlannedStartAt(\DateTimeImmutable $plannedStartAt): static
    {
        $this->plannedStartAt = $plannedStartAt;

        return $this;
    }

    public function getStatus(): MiningOperationStatus
    {
        return $this->status;
    }

    public function setStatus(MiningOperationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getVisibility(): MiningOperationVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(MiningOperationVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getSplitMode(): MiningSplitMode
    {
        return $this->splitMode;
    }

    public function setSplitMode(MiningSplitMode $splitMode): static
    {
        $this->splitMode = $splitMode;

        return $this;
    }

    public function getCorporationId(): ?int
    {
        return $this->corporationId;
    }

    public function setCorporationId(?int $corporationId): static
    {
        $this->corporationId = $corporationId;

        return $this;
    }

    public function getJoinCode(): string
    {
        return $this->joinCode;
    }

    public function setJoinCode(string $joinCode): static
    {
        $this->joinCode = $joinCode;

        return $this;
    }

    public function renewJoinCode(): static
    {
        do {
            $renewedCode = self::generateJoinCode();
        } while ($renewedCode === $this->joinCode);

        $this->joinCode = $renewedCode;

        return $this;
    }

    private static function generateJoinCode(): string
    {
        return bin2hex(random_bytes(self::JOIN_CODE_RANDOM_BYTES));
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getSolarSystemId(): ?int
    {
        return $this->solarSystemId;
    }

    public function getSolarSystemName(): ?string
    {
        return $this->solarSystemName;
    }

    public function start(\DateTimeImmutable $startedAt, ?MapSolarSystem $solarSystem): static
    {
        $this->status = MiningOperationStatus::InProgress;
        $this->startedAt = $startedAt;
        $this->solarSystemId = $solarSystem?->getSolarSystemId();
        $this->solarSystemName = $solarSystem?->getSolarSystemName();

        return $this;
    }

    public function end(\DateTimeImmutable $endedAt): static
    {
        $this->status = MiningOperationStatus::Closing;
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return Collection<int, MiningOperationMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(MiningOperationMember $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setOperation($this);
        }

        return $this;
    }

    public function membershipOf(User $user): ?MiningOperationMember
    {
        foreach ($this->members as $member) {
            if ($member->getUser() === $user) {
                return $member;
            }
        }

        return null;
    }

    public function activeMembershipOf(User $user): ?MiningOperationMember
    {
        $membership = $this->membershipOf($user);

        return $membership?->getStatus() === MiningOperationMemberStatus::Removed ? null : $membership;
    }

    /**
     * @return list<MiningOperationMember>
     */
    public function acceptedMembers(): array
    {
        return array_values($this->members->filter(
            static fn (MiningOperationMember $member): bool => $member->getStatus() === MiningOperationMemberStatus::Accepted,
        )->toArray());
    }

    /**
     * @return list<User>
     */
    public function notifiedUsers(): array
    {
        $users = [spl_object_id($this->owner) => $this->owner];
        foreach ($this->members as $member) {
            if ($member->getStatus() !== MiningOperationMemberStatus::Removed) {
                $users[spl_object_id($member->getUser())] = $member->getUser();
            }
        }

        return array_values($users);
    }

    /**
     * @return list<User>
     */
    public function participantUsers(): array
    {
        $users = [spl_object_id($this->owner) => $this->owner];
        foreach ($this->acceptedMembers() as $member) {
            $users[spl_object_id($member->getUser())] = $member->getUser();
        }

        return array_values($users);
    }

    public function getValuationForm(): MiningValuationForm
    {
        return $this->valuationForm;
    }

    public function getValuationMarket(): MiningValuationMarket
    {
        return $this->valuationMarket;
    }

    public function getValuationSide(): MarketSide
    {
        return $this->valuationSide;
    }

    public function getValuationStructureId(): ?int
    {
        return $this->valuationStructureId;
    }

    public function getValuationStructureName(): ?string
    {
        return $this->valuationStructureName;
    }

    public function getReprocessYield(): ?float
    {
        return $this->reprocessYield;
    }

    public function getBoosterCutPercent(): int
    {
        return $this->boosterCutPercent;
    }

    public function getCorporationCutPercent(): int
    {
        return $this->corporationCutPercent;
    }

    public function getBoosterMember(): ?MiningOperationMember
    {
        return $this->boosterMember;
    }

    public function updateValuationSettings(
        MiningValuationForm $valuationForm = MiningValuationForm::Compressed,
        MiningValuationMarket $valuationMarket = MiningValuationMarket::Jita,
        MarketSide $valuationSide = MarketSide::Buy,
        ?int $structureId = null,
        ?string $structureName = null,
        ?float $reprocessYield = null,
        int $boosterCutPercent = 0,
        int $corporationCutPercent = 0,
        ?MiningOperationMember $boosterMember = null,
    ): void {
    }

    public function getSharesFrozenAt(): ?\DateTimeImmutable
    {
        return $this->sharesFrozenAt;
    }

    public function getPricesSyncedAt(): ?\DateTimeImmutable
    {
        return $this->pricesSyncedAt;
    }

    /**
     * @return Collection<int, MiningFrozenUnitPrice>
     */
    public function getFrozenUnitPrices(): Collection
    {
        return $this->frozenUnitPrices;
    }

    /**
     * @return Collection<int, MiningFrozenMinedValue>
     */
    public function getFrozenMinedValues(): Collection
    {
        return $this->frozenMinedValues;
    }
}
