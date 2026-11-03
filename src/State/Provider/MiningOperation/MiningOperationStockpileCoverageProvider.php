<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationStockpileCoverageResource;
use App\Repository\MiningOperationRepository;
use App\Service\Mining\OreStockpileCoverageService;
use App\Service\MiningOperation\MiningOperationAccess;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationStockpileCoverageResource>
 */
final readonly class MiningOperationStockpileCoverageProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private OreStockpileCoverageService $coverageService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MiningOperationStockpileCoverageResource
    {
        return new MiningOperationStockpileCoverageResource();
    }
}
