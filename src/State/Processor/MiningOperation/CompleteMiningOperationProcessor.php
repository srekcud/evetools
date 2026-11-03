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
use App\Service\MiningOperation\MiningShareFreezer;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, MiningOperationResource>
 */
final readonly class CompleteMiningOperationProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningShareFreezer $freezer,
        private MiningOperationResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['id']);

        $this->access->assertCanAdminister($user, $miningOperation);

        $this->freezer->complete($miningOperation, $user);

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::userIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            'operation_completed',
        );

        return $this->mapper->operationToResource($miningOperation, $miningOperation->activeMembershipOf($user), true);
    }
}
