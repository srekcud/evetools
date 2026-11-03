<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationInvitationResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationEnrolment;
use Symfony\Bundle\SecurityBundle\Security;

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
        return new MiningOperationInvitationResource();
    }
}
