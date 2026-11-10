<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectCockpitResource;
use App\Entity\IndustryProject;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\CockpitPhasePresenter;
use App\Service\Industry\Project\ProjectCockpit;
use App\Service\Industry\Project\ProjectCockpitCalculator;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

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
        private readonly ProjectCockpitInputFactory $inputFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectCockpitResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if ($projectId === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $cockpit = $this->calculator->calculate($this->inputFactory->create($project, $user));

        return $this->mapToResource($project, $cockpit);
    }

    private function mapToResource(IndustryProject $project, ProjectCockpit $cockpit): ProjectCockpitResource
    {
        $resource = new ProjectCockpitResource();
        $resource->projectId = $project->getId()->toRfc4122();
        $resource->status = $cockpit->status->value;
        $resource->phases = CockpitPhasePresenter::of($cockpit->phases);
        $resource->nextAction = [
            'code' => $cockpit->nextAction->code,
            'blocked' => $cockpit->nextAction->blocked,
            'tab' => $cockpit->nextAction->tab,
            'targetStatus' => $cockpit->nextAction->targetStatus,
            'bomLineId' => $cockpit->nextAction->bomLineId,
            'typeId' => $cockpit->nextAction->typeId,
            'typeName' => $cockpit->nextAction->typeName,
            'quantity' => $cockpit->nextAction->quantity,
        ];

        return $resource;
    }
}
