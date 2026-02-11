<?php

declare(strict_types=1);

namespace App\State\Processor\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class DeletePurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        // $data is the StepPurchaseResource returned by the provider
        // The provider already validated ownership
        $this->entityManager->flush();
    }
}
