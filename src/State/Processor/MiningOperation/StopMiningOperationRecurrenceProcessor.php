<?php

declare(strict_types=1);

namespace App\State\Processor\MiningOperation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\MiningOperationRecurrenceRepository;
use App\Service\MiningOperation\MiningOperationRequest;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\ClockInterface;

/**
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class StopMiningOperationRecurrenceProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private MiningOperationRecurrenceRepository $recurrenceRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = MiningOperationRequest::userOf($this->security);

        $recurrence = MiningOperationRequest::activeRecurrenceOf($this->recurrenceRepository, $user, $uriVariables['id']);
        $recurrence->stop($this->clock->now());
        $this->entityManager->flush();

        return null;
    }
}
