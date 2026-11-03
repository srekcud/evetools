<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\MiningOperationCharacterRepository;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationLifecycle;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class RetryMiningLedgerSnapshotProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationCharacterRepository $operationCharacterRepository,
        private MiningOperationAccess $access,
        private MiningOperationLifecycle $lifecycle,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        return null;
    }
}
