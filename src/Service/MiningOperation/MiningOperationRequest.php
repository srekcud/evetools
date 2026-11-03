<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\MiningOperationCharacter;
use App\Entity\MiningOperationMember;
use App\Entity\MiningOperationRecurrence;
use App\Entity\MiningPayment;
use App\Entity\User;
use App\Repository\MiningOperationCharacterRepository;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRecurrenceRepository;
use App\Repository\MiningOperationRepository;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class MiningOperationRequest
{
    private const string OPERATION_NOT_FOUND_MESSAGE = 'Mining operation not found';
    private const string MEMBER_NOT_FOUND_MESSAGE = 'Member not found';
    private const string CHARACTER_NOT_FOUND_MESSAGE = 'Character not found';
    private const string RECURRENCE_NOT_FOUND_MESSAGE = 'Mining operation recurrence not found';
    private const string ARCHIVED_MESSAGE = 'This operation is archived';
    private const string PAYMENT_NOT_FOUND_MESSAGE = 'Payment not found';

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

    public static function activeRecurrenceOf(MiningOperationRecurrenceRepository $repository, User $owner, string $identifier): MiningOperationRecurrence
    {
        return $repository->findActiveOwnedById($owner, UuidOrNotFound::of($identifier, self::RECURRENCE_NOT_FOUND_MESSAGE))
            ?? throw new NotFoundHttpException(self::RECURRENCE_NOT_FOUND_MESSAGE);
    }

    public static function operationByJoinCodeOf(MiningOperationRepository $repository, string $joinCode): MiningOperation
    {
        return $repository->findOneByJoinCode($joinCode)
            ?? throw new NotFoundHttpException(self::OPERATION_NOT_FOUND_MESSAGE);
    }

    public static function assertNotArchived(MiningOperation $operation): void
    {
        if ($operation->getStatus()->isArchived()) {
            throw new BadRequestHttpException(self::ARCHIVED_MESSAGE);
        }
    }

    public static function paymentOf(MiningOperation $operation, string $identifier): MiningPayment
    {
        $paymentId = UuidOrNotFound::of($identifier, self::PAYMENT_NOT_FOUND_MESSAGE)->toRfc4122();
        foreach ($operation->getPayments() as $payment) {
            if ($paymentId === PersistedUuid::of($payment->getId())) {
                return $payment;
            }
        }

        throw new NotFoundHttpException(self::PAYMENT_NOT_FOUND_MESSAGE);
    }

    public static function memberOf(MiningOperationMemberRepository $repository, MiningOperation $operation, string $identifier): MiningOperationMember
    {
        $member = $repository->find(UuidOrNotFound::of($identifier, self::MEMBER_NOT_FOUND_MESSAGE));
        if ($member === null || $member->getOperation() !== $operation) {
            throw new NotFoundHttpException(self::MEMBER_NOT_FOUND_MESSAGE);
        }

        return $member;
    }

    public static function operationCharacterOf(MiningOperationCharacterRepository $repository, MiningOperation $operation, string $identifier): MiningOperationCharacter
    {
        $operationCharacter = $repository->find(UuidOrNotFound::of($identifier, self::CHARACTER_NOT_FOUND_MESSAGE));
        if ($operationCharacter === null || $operationCharacter->getMember()->getOperation() !== $operation) {
            throw new NotFoundHttpException(self::CHARACTER_NOT_FOUND_MESSAGE);
        }

        return $operationCharacter;
    }
}
