<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\ApiResource\Input\Industry\Project\ReviewContributionInput;
use App\Entity\User;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\PilotName;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<ReviewContributionInput, ProjectContributionResource>
 */
class ReviewContributionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly ProjectContributionService $contributionService,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly ProjectResourceMapper $mapper,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectContributionResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        assert($data instanceof ReviewContributionInput);

        $projectId = $uriVariables['projectId'] ?? null;
        $contributionId = $uriVariables['id'] ?? null;

        if ($projectId === null || $contributionId === null) {
            throw new NotFoundHttpException('Contribution not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('Cannot review a contribution of a completed project');
        }

        $contribution = $this->contributionRepository->find(Uuid::fromString($contributionId));
        if ($contribution === null || $contribution->getProject() !== $project) {
            throw new NotFoundHttpException('Contribution not found');
        }

        // The owner may review his own contribution only when nobody else could ever review it.
        $isSoleReviewingOwner = $project->isSoleReviewingOwner($user);
        if ($contribution->getMember()->getUser() === $user && !$isSoleReviewingOwner) {
            throw new BadRequestHttpException('You cannot review your own contribution');
        }

        try {
            if ($data->status === 'approved') {
                $this->contributionService->approve($contribution, $user);
            } elseif ($data->status === 'rejected') {
                $this->contributionService->reject($contribution, $user);
            } else {
                $this->contributionService->reopen($contribution);
            }
        } catch (\DomainException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $action = match ($data->status) {
            'approved' => 'contribution_approved',
            'rejected' => 'contribution_rejected',
            default => 'contribution_synced',
        };
        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            $action,
            [
                'contributionId' => $contribution->getId()->toRfc4122(),
                'reviewedBy' => $user->getMainCharacter()?->getName() ?? 'Unknown',
                'characterName' => PilotName::of($contribution->getMember()->getUser()),
                'bomItemName' => $contribution->getBomItem()?->getTypeName(),
                'quantity' => $contribution->getQuantity(),
            ],
        );

        return $this->mapper->contributionToResource($contribution);
    }
}
