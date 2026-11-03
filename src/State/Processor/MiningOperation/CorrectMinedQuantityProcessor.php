<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\MiningOperation\CorrectMinedQuantityInput;
use App\Repository\MiningOperationCharacterRepository;
use App\Repository\MiningOperationRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\MiningOperation\MinedQuantityCalculator;
use App\Service\MiningOperation\MiningOperationAccess;
use App\Service\TypeNameResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<CorrectMinedQuantityInput, null>
 */
final readonly class CorrectMinedQuantityProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRepository $operationRepository,
        private MiningOperationCharacterRepository $operationCharacterRepository,
        private MiningOperationAccess $access,
        private MinedQuantityCalculator $calculator,
        private TypeNameResolver $typeNameResolver,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        return null;
    }
}
