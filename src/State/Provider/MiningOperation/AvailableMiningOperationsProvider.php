<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Entity\MiningOperation;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationResource>
 */
final readonly class AvailableMiningOperationsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MiningOperationResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = MiningOperationRequest::userOf($this->security);

        $corporationId = $user->getCorporationId();
        if ($corporationId === null) {
            return [];
        }

        return array_map(
            fn (MiningOperation $available): MiningOperationResource => $this->mapper->operationToResource($available, $user, null, true),
            $this->operationRepository->findAvailableForCorporation($corporationId, $user),
        );
    }
}
