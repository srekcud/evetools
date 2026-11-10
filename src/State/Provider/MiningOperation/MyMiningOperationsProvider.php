<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationResource>
 */
final readonly class MyMiningOperationsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MiningOperationResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = MiningOperationRequest::userOf($this->security);

        return array_map(
            fn (MiningOperation $mine): MiningOperationResource => $this->mapper->operationToResource(
                $mine,
                $user,
                $mine->activeMembershipOf($user),
                $this->access->canParticipate($user, $mine),
            ),
            $this->operationRepository->findForParticipant($user),
        );
    }
}
