<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\MiningPresenceRecorder;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, MiningOperationResource>
 */
final readonly class RecordMiningPresenceProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningPresenceRecorder $recorder,
        private MiningOperationResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['id']);

        $presenceChanged = $this->recorder->record($miningOperation, $user);

        $membership = $miningOperation->activeMembershipOf($user) ?? throw new \LogicException('A participant who just beat has a membership');

        if ($presenceChanged) {
            $this->mercurePublisher->publishMiningOperationEvent(
                MiningOperationAudience::participantIdsOf($miningOperation),
                PersistedUuid::of($miningOperation->getId()),
                'presence_changed',
                ['memberId' => PersistedUuid::of($membership->getId())],
            );
        }

        return $this->mapper->operationToResource($miningOperation, $membership);
    }
}
