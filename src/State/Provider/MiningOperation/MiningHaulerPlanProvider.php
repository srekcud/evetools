<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningHaulerPlanResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningHaulerPlanReader;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningHaulerPlanResource>
 */
final readonly class MiningHaulerPlanProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningHaulerPlanReader $reader,
        private MiningHaulerPlanResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningHaulerPlanResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);

        $this->access->assertCanParticipate($user, $miningOperation);
        MiningOperationRequest::assertHasHaulerPlan($miningOperation);

        return $this->mapper->toResource($this->reader->read($miningOperation));
    }
}
