<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProcessorInterface<null, MiningOperationResource>
 */
final readonly class RenewMiningOperationJoinCodeProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningOperationResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['id']);

        $this->access->assertCanAdminister($user, $miningOperation);

        if (!$miningOperation->getStatus()->acceptsParticipants()) {
            throw new BadRequestHttpException('This operation is no longer accepting participants');
        }

        $miningOperation->renewJoinCode();
        $this->entityManager->flush();

        $this->mercurePublisher->publishMiningOperationEvent(MiningOperationAudience::userIdsOf($miningOperation), PersistedUuid::of($miningOperation->getId()), 'join_code_renewed');

        return $this->mapper->operationToResource($miningOperation, $user, $miningOperation->activeMembershipOf($user), true);
    }
}
