<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectSurplusResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectSurplusBuilder;
use App\Service\Industry\Project\ProjectSurplusLine;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectSurplusResource>
 */
final readonly class ProjectSurplusProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectSurplusBuilder $surplusBuilder,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectSurplusResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if (null === $projectId) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $surplus = $this->surplusBuilder->build($project);

        $resource = new ProjectSurplusResource();
        $resource->id = $projectId;
        $resource->totalValue = $surplus->totalValue;
        $resource->lines = array_map(
            static fn (ProjectSurplusLine $line): array => [
                'lineId' => $line->lineId,
                'typeId' => $line->typeId,
                'typeName' => $line->typeName,
                'requiredQuantity' => $line->requiredQuantity,
                'contributedQuantity' => $line->contributedQuantity,
                'surplusQuantity' => $line->surplusQuantity,
                'unitPrice' => $line->unitPrice,
                'surplusValue' => $line->surplusValue,
                'source' => $line->source->value,
            ],
            $surplus->lines,
        );

        return $resource;
    }
}
