<?php

declare(strict_types=1);

namespace App\Tests\Unit\State\Provider\GroupIndustry;

use App\Entity\GroupIndustryProject;
use App\Entity\GroupIndustryProjectMember;
use App\Entity\User;
use App\Enum\GroupMemberRole;
use App\Enum\GroupMemberStatus;
use App\Enum\GroupProjectStatus;
use App\Repository\GroupIndustryProjectMemberRepository;
use App\Repository\GroupIndustryProjectRepository;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Uid\Uuid;

trait GroupProjectAccessProfiles
{
    private const int OWNER_CORPORATION_ID = 98000001;
    private const int FOREIGN_CORPORATION_ID = 98000002;

    private const string GRANTED = 'granted';
    private const string NO_ACCESS = '403 You do not have access to this project';
    private const string ADMIN_OR_OWNER_ONLY = '403 Only project owner or admin can perform this action';
    private const string PROJECT_NOT_FOUND = '404 Project not found';
    private const string UNAUTHORIZED = '401 Unauthorized';

    private const string OWNER = 'owner';
    private const string ACCEPTED_ADMIN = 'accepted admin from a foreign corporation';
    private const string ACCEPTED_MEMBER = 'accepted member from a foreign corporation';
    private const string PENDING_MEMBER = 'pending member from a foreign corporation';
    private const string OWNER_CORPORATION_NON_MEMBER = 'non member of the owner corporation';
    private const string OWNER_CORPORATION_PENDING_MEMBER = 'pending member of the owner corporation';
    private const string OWNER_CORPORATION_REMOVED_FORMER_ADMIN = 'removed former admin of the owner corporation';
    private const string REMOVED_MEMBER = 'removed member from a foreign corporation';
    private const string STRANGER = 'stranger from a foreign corporation';
    private const string USER_WITHOUT_CORPORATION = 'user without known corporation';
    private const string ACCEPTED_ADMIN_OF_COMPLETED_PROJECT = 'accepted admin of a completed project';
    private const string STRANGER_TO_COMPLETED_PROJECT = 'stranger to a completed project';

    private GroupIndustryProject $sabreBatch;
    private User&Stub $sabreBatchOwner;

    private array $sabreBatchMemberships = [];

    public static function readAccessMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'A3 accepted member' => [self::ACCEPTED_MEMBER, self::GRANTED];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::NO_ACCESS];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::GRANTED];
        yield 'A6 owner corporation pending member' => [self::OWNER_CORPORATION_PENDING_MEMBER, self::GRANTED];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::GRANTED];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::NO_ACCESS];
        yield 'A9 stranger' => [self::STRANGER, self::NO_ACCESS];
        yield 'A10 user without corporation' => [self::USER_WITHOUT_CORPORATION, self::NO_ACCESS];
        yield 'A12 accepted admin of a completed project' => [self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT, self::GRANTED];
        yield 'A13 stranger to a completed project' => [self::STRANGER_TO_COMPLETED_PROJECT, self::NO_ACCESS];
    }

    public static function participationAccessMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'A3 accepted member' => [self::ACCEPTED_MEMBER, self::GRANTED];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::NO_ACCESS];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::NO_ACCESS];
        yield 'A6 owner corporation pending member' => [self::OWNER_CORPORATION_PENDING_MEMBER, self::NO_ACCESS];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::NO_ACCESS];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::NO_ACCESS];
        yield 'A9 stranger' => [self::STRANGER, self::NO_ACCESS];
        yield 'A10 user without corporation' => [self::USER_WITHOUT_CORPORATION, self::NO_ACCESS];
        yield 'A12 accepted admin of a completed project' => [self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT, self::GRANTED];
        yield 'A13 stranger to a completed project' => [self::STRANGER_TO_COMPLETED_PROJECT, self::NO_ACCESS];
    }

    public static function administrationAccessMatrix(): iterable
    {
        yield 'A1 owner' => [self::OWNER, self::GRANTED];
        yield 'A2 accepted admin' => [self::ACCEPTED_ADMIN, self::GRANTED];
        yield 'A3 accepted member' => [self::ACCEPTED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A4 pending member' => [self::PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A5 owner corporation without membership' => [self::OWNER_CORPORATION_NON_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A6 owner corporation pending member' => [self::OWNER_CORPORATION_PENDING_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A7 owner corporation removed former admin' => [self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN, self::ADMIN_OR_OWNER_ONLY];
        yield 'A8 removed member' => [self::REMOVED_MEMBER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A9 stranger' => [self::STRANGER, self::ADMIN_OR_OWNER_ONLY];
        yield 'A10 user without corporation' => [self::USER_WITHOUT_CORPORATION, self::ADMIN_OR_OWNER_ONLY];
        yield 'A12 accepted admin of a completed project' => [self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT, self::GRANTED];
        yield 'A13 stranger to a completed project' => [self::STRANGER_TO_COMPLETED_PROJECT, self::ADMIN_OR_OWNER_ONLY];
    }

    private function createSabreBatch(?int $ownerCorporationId = self::OWNER_CORPORATION_ID, bool $withOwnerMembership = true): void
    {
        $this->sabreBatchOwner = $this->userOfCorporation($ownerCorporationId);

        $this->sabreBatch = new GroupIndustryProject();
        $this->sabreBatch->setOwner($this->sabreBatchOwner);
        $this->sabreBatch->setName('Sabre batch');
        $this->sabreBatch->setStatus(GroupProjectStatus::InProgress);
        (new \ReflectionProperty(GroupIndustryProject::class, 'id'))->setValue($this->sabreBatch, Uuid::v4());

        if ($withOwnerMembership) {
            $this->addSabreBatchMembership($this->sabreBatchOwner, GroupMemberRole::Owner, GroupMemberStatus::Accepted);
        }
    }

    private function sabreBatchProjectRepository(): GroupIndustryProjectRepository&Stub
    {
        $projectRepository = $this->createStub(GroupIndustryProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(
            fn (Uuid $id): ?GroupIndustryProject => $id->equals($this->sabreBatch->getId()) ? $this->sabreBatch : null,
        );

        return $projectRepository;
    }

    private function sabreBatchMemberRepository(): GroupIndustryProjectMemberRepository&Stub
    {
        $memberRepository = $this->createStub(GroupIndustryProjectMemberRepository::class);
        $memberRepository->method('findOneBy')->willReturnCallback($this->sabreBatchMembershipLookup());
        $memberRepository->method('find')->willReturnCallback($this->sabreBatchMembershipById());

        return $memberRepository;
    }

    private function sabreBatchMembershipLookup(): \Closure
    {
        return function (array $criteria): ?GroupIndustryProjectMember {
            foreach ($this->sabreBatchMemberships as $membership) {
                if ($this->membershipMeetsEveryCriterion($membership, $criteria)) {
                    return $membership;
                }
            }

            return null;
        };
    }

    private function sabreBatchMembershipById(): \Closure
    {
        return function (Uuid $id): ?GroupIndustryProjectMember {
            foreach ($this->sabreBatchMemberships as $membership) {
                if ($id->equals($membership->getId())) {
                    return $membership;
                }
            }

            return null;
        };
    }

    private function userWithProfile(string $profile): User
    {
        return match ($profile) {
            self::OWNER => $this->sabreBatchOwner,
            self::ACCEPTED_ADMIN => $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted),
            self::ACCEPTED_MEMBER => $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Accepted),
            self::PENDING_MEMBER => $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Pending),
            self::OWNER_CORPORATION_NON_MEMBER => $this->userOfCorporation(self::OWNER_CORPORATION_ID),
            self::OWNER_CORPORATION_PENDING_MEMBER => $this->sabreBatchMember(self::OWNER_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Pending),
            self::OWNER_CORPORATION_REMOVED_FORMER_ADMIN => $this->sabreBatchMember(self::OWNER_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Removed),
            self::REMOVED_MEMBER => $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Member, GroupMemberStatus::Removed),
            self::STRANGER => $this->userOfCorporation(self::FOREIGN_CORPORATION_ID),
            self::USER_WITHOUT_CORPORATION => $this->userOfCorporation(null),
            self::ACCEPTED_ADMIN_OF_COMPLETED_PROJECT => $this->onCompletedSabreBatch(
                $this->sabreBatchMember(self::FOREIGN_CORPORATION_ID, GroupMemberRole::Admin, GroupMemberStatus::Accepted),
            ),
            self::STRANGER_TO_COMPLETED_PROJECT => $this->onCompletedSabreBatch($this->userOfCorporation(self::FOREIGN_CORPORATION_ID)),
        };
    }

    private function onCompletedSabreBatch(User $user): User
    {
        $this->sabreBatch->setStatus(GroupProjectStatus::Completed);

        return $user;
    }

    private function sabreBatchMember(?int $corporationId, GroupMemberRole $role, GroupMemberStatus $status): User
    {
        $user = $this->userOfCorporation($corporationId);
        $this->addSabreBatchMembership($user, $role, $status);

        return $user;
    }

    private function addSabreBatchMembership(User $user, GroupMemberRole $role, GroupMemberStatus $status): GroupIndustryProjectMember
    {
        $membership = new GroupIndustryProjectMember();
        $membership->setUser($user);
        $membership->setRole($role);
        $membership->setStatus($status);
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($membership, Uuid::v4());
        $this->sabreBatch->addMember($membership);

        $this->sabreBatchMemberships[] = $membership;

        return $membership;
    }

    private function sabreBatchMemberOfAnotherProject(?int $corporationId, GroupMemberRole $role, GroupMemberStatus $status): User
    {
        $user = $this->userOfCorporation($corporationId);

        $cutlassBatch = new GroupIndustryProject();
        $cutlassBatch->setOwner($this->userOfCorporation(self::FOREIGN_CORPORATION_ID));
        $cutlassBatch->setName('Cutlass batch');
        $cutlassBatch->setStatus(GroupProjectStatus::InProgress);
        (new \ReflectionProperty(GroupIndustryProject::class, 'id'))->setValue($cutlassBatch, Uuid::v4());

        $membership = new GroupIndustryProjectMember();
        $membership->setUser($user);
        $membership->setRole($role);
        $membership->setStatus($status);
        (new \ReflectionProperty(GroupIndustryProjectMember::class, 'id'))->setValue($membership, Uuid::v4());
        $cutlassBatch->addMember($membership);

        $this->sabreBatchMemberships[] = $membership;

        return $user;
    }

    private function userOfCorporation(?int $corporationId): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getCorporationId')->willReturn($corporationId);

        return $user;
    }

    private function outcomeOf(\Closure $request): string
    {
        try {
            $granted = $request();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() . ' ' . $exception->getMessage();
        } catch (\Throwable $throwable) {
            return $throwable::class . ': ' . $throwable->getMessage();
        }

        return is_string($granted) ? $granted : self::GRANTED;
    }

    private function membershipMeetsEveryCriterion(GroupIndustryProjectMember $membership, array $criteria): bool
    {
        $values = [
            'project' => $membership->getProject(),
            'user' => $membership->getUser(),
            'role' => $membership->getRole(),
            'status' => $membership->getStatus(),
        ];

        foreach ($criteria as $field => $expected) {
            if (!array_key_exists($field, $values)) {
                self::fail("Unexpected criterion '{$field}'");
            }
            $accepted = is_array($expected) ? $expected : [$expected];
            if (!in_array($values[$field], $accepted, true)) {
                return false;
            }
        }

        return true;
    }
}
