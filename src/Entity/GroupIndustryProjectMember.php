<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: GroupIndustryProjectMemberRepository::class)]
#[ORM\Table(name: 'group_industry_project_members')]
#[ORM\Index(columns: ['project_id'])]
#[ORM\Index(columns: ['user_id'])]
#[ORM\UniqueConstraint(columns: ['project_id', 'user_id'])]
class GroupIndustryProjectMember
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private ?Uuid $id = null;

    #[ORM\ManyToOne(targetEntity: GroupIndustryProject::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private GroupIndustryProject $project;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 10, enumType: GroupMemberRole::class)]
    private GroupMemberRole $role = GroupMemberRole::Member;

    #[ORM\Column(type: 'string', length: 10, enumType: GroupMemberStatus::class)]
    private GroupMemberStatus $status = GroupMemberStatus::Pending;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $joinedAt;

    public function __construct()
    {
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getProject(): GroupIndustryProject
    {
        return $this->project;
    }

    public function setProject(GroupIndustryProject $project): static
    {
        $this->project = $project;
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
}
