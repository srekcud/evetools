<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Repository\IndustryProjectTemplateRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProcessorInterface<mixed, void>
 */
class DeleteProjectTemplateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly IndustryProjectTemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        throw new \LogicException('not implemented');
    }
}
