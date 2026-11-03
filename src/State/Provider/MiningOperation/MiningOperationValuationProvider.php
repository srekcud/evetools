<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationValuationResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use App\Service\MiningOperation\MiningOperationValuator;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationValuationResource>
 */
final readonly class MiningOperationValuationProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningOperationValuator $valuator,
        private TypeNameResolver $typeNameResolver,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationValuationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        if ($operation instanceof Patch) {
            $this->access->assertCanAdminister($user, $miningOperation);
        } else {
            $this->access->assertCanParticipate($user, $miningOperation);
        }

        $valuation = $this->valuator->value($miningOperation);

        return $this->mapper->valuationToResource(
            $miningOperation,
            $valuation,
            $this->typeNameResolver->findNames(array_keys($valuation->unitPricesByTypeId)),
            $this->access->canAdminister($user, $miningOperation),
        );
    }
}
