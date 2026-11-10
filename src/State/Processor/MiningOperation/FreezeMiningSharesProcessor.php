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
use App\Service\MiningOperation\MiningOperationNotifier;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\MiningShareFreezer;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, MiningOperationResource>
 */
final readonly class FreezeMiningSharesProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningShareFreezer $freezer,
        private MiningOperationResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
        private MiningOperationNotifier $notifier,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['id']);

        $this->access->assertCanAdminister($user, $miningOperation);

        $this->freezer->freeze($miningOperation);

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::participantIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            'shares_frozen',
        );
        $this->notifier->sharesFrozen($miningOperation);

        return $this->mapper->operationToResource($miningOperation, $user, $miningOperation->activeMembershipOf($user), true);
    }
}
