<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\CreateMiningOperationInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Service\MiningOperation\MiningBoostFit;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\Service\MiningOperation\MiningOperationRequest;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<CreateMiningOperationInput, MiningOperationResource>
 */
final readonly class CreateMiningOperationProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationEnrolment $enrolment,
        private MiningOperationResourceMapper $mapper,
        private MiningBoostFit $boostFit,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationResource
    {
        $user = MiningOperationRequest::userOf($this->security);

        \assert($data instanceof CreateMiningOperationInput);

        $this->boostFit->assertConsistent($data->declaredBoostShip(), $data->declaredCompressorKinds());

        $miningOperation = $this->enrolment->create($user, $data);

        return $this->mapper->operationToResource($miningOperation, $miningOperation->membershipOf($user), true);
    }
}
