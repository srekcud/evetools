<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Enum\MiningOperationMemberStatus;
use App\Enum\MiningOperationStatus;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProcessorInterface<null, null>
 */
final readonly class LeaveMiningOperationProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['id']);

        $membership = $miningOperation->activeMembershipOf($user);
        if ($membership === null) {
            throw new NotFoundHttpException('Membership not found');
        }

        if ($miningOperation->getOwner() === $user) {
            throw new BadRequestHttpException('The owner cannot leave the operation');
        }

        if ($membership->getStatus() === MiningOperationMemberStatus::Accepted && $miningOperation->getStatus() !== MiningOperationStatus::Planned) {
            throw new BadRequestHttpException('Only a planned operation can be left');
        }

        MiningOperationRequest::assertNotArchived($miningOperation);

        $membership->remove();
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::userIdsOf($miningOperation, $user),
            PersistedUuid::of($miningOperation->getId()),
            'member_removed',
            ['memberId' => PersistedUuid::of($membership->getId())],
        );

        return null;
    }
}
