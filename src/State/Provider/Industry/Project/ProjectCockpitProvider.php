<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectCockpitResource;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<ProjectCockpitResource>
 */
class ProjectCockpitProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectCockpitCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectCockpitResource
    {
        return new ProjectCockpitResource();
    }
}
