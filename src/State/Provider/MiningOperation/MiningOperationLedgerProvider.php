<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationLedgerResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationLifecycle;
use App\Service\TypeNameResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationLedgerResource>
 */
final readonly class MiningOperationLedgerProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MinedQuantityCalculator $calculator,
        private MiningOperationLifecycle $lifecycle,
        private TypeNameResolver $typeNameResolver,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationLedgerResource
    {
        return new MiningOperationLedgerResource();
    }
}
