<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\UpdateMiningFleetStorageInput;
use App\ApiResource\MiningOperation\MiningFleetStorageResource;
use App\Repository\MiningOperationMemberRepository;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MiningFleetStorageDeclarations;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\TypeNameResolver;
use App\State\Provider\MiningOperation\MiningOperationResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<UpdateMiningFleetStorageInput, MiningFleetStorageResource>
 */
final readonly class UpdateMiningFleetStorageProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationMemberRepository $memberRepository,
        private MiningFleetStorageDeclarations $declarations,
        private MiningOperationAccess $access,
        private TypeNameResolver $typeNameResolver,
        private MiningOperationResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MiningFleetStorageResource
    {
        return new MiningFleetStorageResource();
    }
}
