<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\PlacedActionResource;
use App\Entity\User;
use App\Enum\StructureAccessStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\Action\LocatedAction;
use App\Service\Industry\Project\Action\UserActionReader;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlacedActionResource>
 */
class PlacedActionsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly UserActionReader $reader,
    ) {
    }

    /**
     * @return list<PlacedActionResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if (null === $projectId) {
            return array_map($this->toResource(...), $this->reader->ofUser($user));
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        return array_map($this->toResource(...), $this->reader->ofProject($user, $project));
    }

    private function toResource(LocatedAction $located): PlacedActionResource
    {
        $action = $located->action;

        $resource = new PlacedActionResource();
        $resource->id = $action->id();
        $resource->projectId = $action->projectId;
        $resource->projectName = $action->projectName;
        $resource->code = $action->code->value;
        $resource->bomLineId = $action->bomLineId;
        $resource->typeId = $action->typeId;
        $resource->typeName = $action->typeName;
        $resource->quantity = $action->quantity;
        $resource->place = $action->place->toArray();
        $resource->here = $located->here;
        $resource->access = match ($action->access) {
            null => null,
            StructureAccessStatus::Granted => 'granted',
            StructureAccessStatus::Unknown => 'unknown',
            StructureAccessStatus::Denied => throw new \LogicException('An action denied to the pilot is never offered.'),
        };

        return $resource;
    }
}
