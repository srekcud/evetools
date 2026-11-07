<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\IndustryProjectTemplateResource;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectTemplateRepository;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, IndustryProjectTemplateResource>
 */
class CreateProjectTemplateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly IndustryProjectTemplateRepository $templateRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IndustryProjectTemplateResource
    {
        throw new \LogicException('not implemented');
    }
}
