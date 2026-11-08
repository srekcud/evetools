<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Mining\OreSaleResource;
use App\Repository\MiningOperationRepository;
use App\Service\Mining\OreSaleService;
use App\Service\MiningOperation\MinedOreCompressedQuantities;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<OreSaleResource>
 */
final readonly class MiningOperationOreSaleProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private MiningOperationRepository $operationRepository,
        private MiningOperationAccess $access,
        private MinedQuantityCalculator $calculator,
        private MinedOreCompressedQuantities $compressedQuantities,
        private OreSaleService $oreSaleService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OreSaleResource
    {
        return new OreSaleResource();
    }
}
