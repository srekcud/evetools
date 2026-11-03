<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Enum\MiningOperationMemberRole;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningOperationVisibility;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class MiningOperationAccess
{
    private const string NO_ACCESS_MESSAGE = 'You do not have access to this operation';
    private const string NOT_AN_ADMINISTRATOR_MESSAGE = 'Only the operation owner or an admin can perform this action';
    private const string ADMIN_MANAGED_BY_OWNER_MESSAGE = 'Only the operation owner can manage an admin';

    public function canRead(User $user, MiningOperation $operation): bool
    {
        return $this->canParticipate($user, $operation) || $this->readsThroughCorporation($user, $operation);
    }

    public function canParticipate(User $user, MiningOperation $operation): bool
    {
        return $operation->getOwner() === $user
            || $operation->membershipOf($user)?->getStatus() === MiningOperationMemberStatus::Accepted;
    }

    public function canAdminister(User $user, MiningOperation $operation): bool
    {
        if ($operation->getOwner() === $user) {
            return true;
        }

        $membership = $operation->membershipOf($user);

        return $membership?->getStatus() === MiningOperationMemberStatus::Accepted
            && $membership->getRole() === MiningOperationMemberRole::Admin;
    }

    public function canRetrySnapshotOf(User $user, MiningOperationCharacter $operationCharacter): bool
    {
        $operation = $operationCharacter->getMember()->getOperation();
        if ($this->canAdminister($user, $operation)) {
            return true;
        }

        return $operation->getStatus() !== MiningOperationStatus::Closing && $operationCharacter->getMember()->getUser() === $user;
    }

    public function assertCanRead(User $user, MiningOperation $operation): void
    {
        if (!$this->canRead($user, $operation)) {
            throw new AccessDeniedHttpException(self::NO_ACCESS_MESSAGE);
        }
    }

    public function assertCanReadResults(User $user, MiningOperation $operation): void
    {
        if ($operation->getStatus()->isArchived()) {
            $this->assertCanRead($user, $operation);

            return;
        }

        $this->assertCanParticipate($user, $operation);
    }

    public function assertCanParticipate(User $user, MiningOperation $operation): void
    {
        if (!$this->canParticipate($user, $operation)) {
            throw new AccessDeniedHttpException(self::NO_ACCESS_MESSAGE);
        }
    }

    public function assertCanAdminister(User $user, MiningOperation $operation): void
    {
        if (!$this->canAdminister($user, $operation)) {
            throw new AccessDeniedHttpException(self::NOT_AN_ADMINISTRATOR_MESSAGE);
        }
    }

    public function assertCanManageMember(User $user, MiningOperation $operation, MiningOperationMember $member): void
    {
        if ($operation->getOwner() !== $user && $member->getRole() === MiningOperationMemberRole::Admin) {
            throw new AccessDeniedHttpException(self::ADMIN_MANAGED_BY_OWNER_MESSAGE);
        }
    }

    private function readsThroughCorporation(User $user, MiningOperation $operation): bool
    {
        $corporationId = $user->getCorporationId();

        return $operation->getVisibility() === MiningOperationVisibility::Corporation
            && $corporationId !== null
            && $corporationId === $operation->getCorporationId();
    }
}
