<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningPaymentResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningPaymentResource>
 */
final readonly class MiningPaymentCollectionProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MiningPaymentResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return [];
    }
}
