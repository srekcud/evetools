<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Repository\MiningOperationMemberRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MiningOperationMemberRepository::class)]
#[ORM\Table(name: 'mining_operation_members')]
#[ORM\Index(columns: ['user_id'])]
#[ORM\UniqueConstraint(columns: ['operation_id', 'user_id'])]
class MiningOperationMember
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: MiningOperation::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MiningOperation $operation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 10, enumType: MiningOperationMemberRole::class)]
    private MiningOperationMemberRole $role = MiningOperationMemberRole::Member;

    #[ORM\Column(type: 'string', length: 10, enumType: MiningOperationMemberStatus::class)]
    private MiningOperationMemberStatus $status = MiningOperationMemberStatus::Pending;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $joinedAt;

    /** @var Collection<int, MiningOperationCharacter> */
    #[ORM\OneToMany(targetEntity: MiningOperationCharacter::class, mappedBy: 'member', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $characters;

    public function __construct()
    {
        $this->characters = new ArrayCollection();
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getOperation(): MiningOperation
    {
        return $this->operation;
    }

    public function setOperation(MiningOperation $operation): static
    {
        $this->operation = $operation;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRole(): MiningOperationMemberRole
    {
        return $this->role;
    }

    public function setRole(MiningOperationMemberRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getStatus(): MiningOperationMemberStatus
    {
        return $this->status;
    }

    public function setStatus(MiningOperationMemberStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /**
     * @return Collection<int, MiningOperationCharacter>
     */
    public function getCharacters(): Collection
    {
        return $this->characters;
    }

    public function enrol(Character $character): static
    {
        $this->characters->add(new MiningOperationCharacter($this, $character));

        return $this;
    }

    public function remove(): static
    {
        $this->status = MiningOperationMemberStatus::Removed;
        $this->role = MiningOperationMemberRole::Member;
        $this->characters->clear();

        return $this;
    }

    /**
     * @param list<Character> $characters
     */
    public function requestRejoin(array $characters): static
    {
        $this->status = MiningOperationMemberStatus::Pending;
        $this->role = MiningOperationMemberRole::Member;
        $this->joinedAt = new \DateTimeImmutable();
        $this->characters->clear();
        foreach ($characters as $character) {
            $this->enrol($character);
        }

        return $this;
    }
}
