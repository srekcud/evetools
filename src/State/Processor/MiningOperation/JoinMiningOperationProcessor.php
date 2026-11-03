<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\JoinMiningOperationInput;
use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningOperationAudience;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\Service\MiningOperation\MiningOperationLifecycle;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\PersistedUuid;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<JoinMiningOperationInput, MiningOperationInvitationResource>
 */
final readonly class JoinMiningOperationProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationEnrolment $enrolment,
        private MiningOperationResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
        private MiningOperationLifecycle $lifecycle,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationInvitationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof JoinMiningOperationInput);

        $miningOperation = MiningOperationRequest::operationByJoinCodeOf($this->operationRepository, $uriVariables['code']);

        $membership = $this->enrolment->join($user, $miningOperation, $data->characterIds);
        $this->lifecycle->snapshotLateParticipant($membership);

        $this->mercurePublisher->publishMiningOperationEvent(
            MiningOperationAudience::userIdsOf($miningOperation),
            PersistedUuid::of($miningOperation->getId()),
            'member_joined',
            ['memberId' => PersistedUuid::of($membership->getId()), 'status' => $membership->getStatus()->value],
        );

        return $this->mapper->invitationToResource(
            $miningOperation,
            $membership,
            $this->enrolment->autoAccepts($user, $miningOperation),
            $this->enrolment->eligibilityOf($user),
        );
    }
}
