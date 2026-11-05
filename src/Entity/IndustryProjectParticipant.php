<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\IndustryProjectParticipantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: IndustryProjectParticipantRepository::class)]
#[ORM\Table(name: 'industry_project_participants')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\Index(columns: ['user_id'])]
#[ORM\UniqueConstraint(columns: ['project_id', 'user_id'])]
class IndustryProjectParticipant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: IndustryProject::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private IndustryProject $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 10, enumType: GroupMemberRole::class)]
    private GroupMemberRole $role = GroupMemberRole::Member;

    #[ORM\Column(type: 'string', length: 10, enumType: GroupMemberStatus::class)]
    private GroupMemberStatus $status = GroupMemberStatus::Pending;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProject(): IndustryProject
    {
        return $this->project;
    }

    public function setProject(IndustryProject $project): static
    {
        $this->project = $project;
        return $this;
    }

    public function anonymize(): void
    {
        $this->user = null;
        $this->status = GroupMemberStatus::Removed;
        $this->role = GroupMemberRole::Member;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getRole(): GroupMemberRole
    {
        return $this->role;
    }

    public function setRole(GroupMemberRole $role): static
    {
        $this->role = $role;
        return $this;
    }

    public function getStatus(): GroupMemberStatus
    {
        return $this->status;
    }

    public function setStatus(GroupMemberStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }

    /** A removed member asking to join again goes back to a pending plain membership. */
    public function requestRejoin(): static
    {
        $this->status = GroupMemberStatus::Pending;
        $this->role = GroupMemberRole::Member;
        $this->joinedAt = new \DateTimeImmutable();

        return $this;
    }
}
