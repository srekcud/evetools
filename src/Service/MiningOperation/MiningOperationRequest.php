<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationMember;
use App\Entity\User;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRepository;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class MiningOperationRequest
{
    private const string OPERATION_NOT_FOUND_MESSAGE = 'Mining operation not found';
    private const string MEMBER_NOT_FOUND_MESSAGE = 'Member not found';

    public static function userOf(Security $security): User
    {
        $user = $security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        return $user;
    }

    public static function operationOf(MiningOperationRepository $repository, string $identifier): MiningOperation
    {
        return $repository->find(UuidOrNotFound::of($identifier, self::OPERATION_NOT_FOUND_MESSAGE))
            ?? throw new NotFoundHttpException(self::OPERATION_NOT_FOUND_MESSAGE);
    }

    public static function operationByJoinCodeOf(MiningOperationRepository $repository, string $joinCode): MiningOperation
    {
        return $repository->findOneByJoinCode($joinCode)
            ?? throw new NotFoundHttpException(self::OPERATION_NOT_FOUND_MESSAGE);
    }

    public static function memberOf(MiningOperationMemberRepository $repository, MiningOperation $operation, string $identifier): MiningOperationMember
    {
        $member = $repository->find(UuidOrNotFound::of($identifier, self::MEMBER_NOT_FOUND_MESSAGE));
        if ($member === null || $member->getOperation() !== $operation) {
            throw new NotFoundHttpException(self::MEMBER_NOT_FOUND_MESSAGE);
        }

        return $member;
    }
}
