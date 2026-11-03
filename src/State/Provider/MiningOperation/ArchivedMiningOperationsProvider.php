<?php

declare(strict_types=1);

namespace App\State\Provider\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationArchive;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<MiningOperationResource>
 */
final readonly class ArchivedMiningOperationsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationArchive $archive,
        private MiningOperationResourceMapper $mapper,
    ) {
    }

    /**
     * @return list<MiningOperationResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return [];
    }
}
