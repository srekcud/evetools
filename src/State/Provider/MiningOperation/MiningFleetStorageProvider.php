<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningFleetStorageResource>
 */
final readonly class MiningFleetStorageProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private TypeNameResolver $typeNameResolver,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningFleetStorageResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        if ($operation instanceof Patch) {
            $this->access->assertCanAdminister($user, $miningOperation);
        } else {
            $this->access->assertCanReadResults($user, $miningOperation);
        }

        return $this->mapper->fleetStorageToResource(
            $miningOperation,
            $user,
            $this->access->canAdminister($user, $miningOperation),
            $this->typeNameResolver->findNames($this->mapper->fleetStorageTypeIdsOf($miningOperation)),
        );
    }
}
