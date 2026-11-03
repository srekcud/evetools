<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Service\MiningOperation\MiningOperationEnrolment;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class CreateMiningOperationProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationEnrolment $enrolment,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningOperationResource
    {
        return new MiningOperationResource();
    }
}
