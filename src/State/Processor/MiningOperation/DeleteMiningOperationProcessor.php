<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Enum\MiningOperationStatus;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteMiningOperationProcessor implements ProcessorInterface
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

        if ($miningOperation->getOwner() !== $user) {
            throw new AccessDeniedHttpException('Only the operation owner can delete it');
        }

        if ($miningOperation->getStatus() !== MiningOperationStatus::Planned) {
            throw new BadRequestHttpException('Only a planned operation can be deleted');
        }

        $notifiedUserIds = MiningOperationAudience::userIdsOf($miningOperation);
        $operationId = PersistedUuid::of($miningOperation->getId());

        $this->entityManager->remove($miningOperation);
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent($notifiedUserIds, $operationId, 'operation_deleted');

        return null;
    }
}
