<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningFleetStorageVerificationResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningFleetStorageVerifier;
use App\Service\MiningOperation\MiningOperationAccess;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningFleetStorageVerificationResource>
 */
final readonly class MiningFleetStorageVerificationProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningFleetStorageVerifier $verifier,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningFleetStorageVerificationResource
    {
        return new MiningFleetStorageVerificationResource();
    }
}
