<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectIntermediateStockProposalResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\IntermediateStockProposalLine;
use App\Service\Industry\Project\IntermediateStockProposer;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectIntermediateStockProposalResource>
 */
final readonly class ProjectIntermediateStockProposalProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private IntermediateStockProposer $proposer,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectIntermediateStockProposalResource
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

        $this->accessChecker->assertOwner($user, $project);

        $proposal = $this->proposer->proposalOf($project);

        $resource = new ProjectIntermediateStockProposalResource();
        $resource->id = $projectId;
        $resource->state = $proposal->state;
        $resource->unknownReason = $proposal->unknownReason;
        $resource->lines = array_map(
            static fn (IntermediateStockProposalLine $line): array => [
                'bomItemId' => $line->bomItemId,
                'typeId' => $line->typeId,
                'typeName' => $line->typeName,
                'demandQuantity' => $line->demandQuantity,
                'remainingQuantity' => $line->remainingQuantity,
                'personalQuantity' => $line->personalQuantity,
                'containerQuantity' => $line->containerQuantity,
                'stockQuantity' => $line->stockQuantity,
                'proposedQuantity' => $line->proposedQuantity,
            ],
            $proposal->lines,
        );

        return $resource;
    }
}
