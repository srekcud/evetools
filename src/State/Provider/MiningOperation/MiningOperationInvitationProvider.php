<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationInvitationResource>
 */
final readonly class MiningOperationInvitationProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationEnrolment $enrolment,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationInvitationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationByJoinCodeOf($this->operationRepository, $uriVariables['code']);

        return $this->mapper->invitationToResource(
            $miningOperation,
            $miningOperation->membershipOf($user),
            $this->enrolment->autoAccepts($user, $miningOperation),
            $this->enrolment->eligibilityOf($user),
        );
    }
}
