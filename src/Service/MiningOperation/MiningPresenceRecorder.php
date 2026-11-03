<?php

declare(strict_types=1);

namespace App\Service\MiningOperation;

use App\Entity\MiningOperation;
use App\Entity\User;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Enum\MiningPresenceState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class MiningPresenceRecorder
{
    public function __construct(
        private MiningPresenceResolver $resolver,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return bool true when the participant was not online before this heartbeat
     */
    public function record(MiningOperation $operation, User $user): bool
    {
        $member = $operation->membershipOf($user);
        if ($member?->getStatus() !== MiningOperationMemberStatus::Accepted) {
            throw new NotFoundHttpException('Membership not found');
        }
        if ($operation->getStatus() !== MiningOperationStatus::InProgress) {
            throw new BadRequestHttpException('Presence is only tracked while an operation is in progress');
        }
        if ($member->getDepartedAt() !== null) {
            throw new BadRequestHttpException('You already left this operation');
        }

        $wasOnline = $this->resolver->resolve($member)?->state === MiningPresenceState::Online;
        $member->markSeen($this->clock->now());
        $this->entityManager->flush();

        return !$wasOnline;
    }
}
