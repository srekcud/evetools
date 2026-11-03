<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\MiningOperationCharacterRepository;
use App\Repository\MiningOperationRepository;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\MiningOperation\MiningOperationLifecycle;
use App\Service\MiningOperation\MiningOperationRequest;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

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
        $user = MiningOperationRequest::userOf($this->security);

        $miningOperation = MiningOperationRequest::operationOf($this->operationRepository, $uriVariables['operationId']);
        $operationCharacter = MiningOperationRequest::operationCharacterOf($this->operationCharacterRepository, $miningOperation, $uriVariables['id']);

        if ($operationCharacter->getMember()->getUser() !== $user && !$this->access->canAdminister($user, $miningOperation)) {
            throw new AccessDeniedHttpException('Only the character owner or an admin can retry its snapshot');
        }

        $this->lifecycle->retrySnapshot($operationCharacter);

        return null;
    }
}
