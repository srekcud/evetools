<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\Entity\User;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\PilotName;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<ProjectContributionResource, void>
 */
class WithdrawContributionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryProjectRepository $projectRepository,
        private readonly IndustryProjectContributionRepository $contributionRepository,
        private readonly ProjectContributionService $contributionService,
        private readonly ProjectAccessChecker $accessChecker,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        $contributionId = $uriVariables['id'] ?? null;

        if ($projectId === null || $contributionId === null) {
            throw new NotFoundHttpException('Contribution not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $contribution = $this->contributionRepository->find(Uuid::fromString($contributionId));
        if ($contribution === null || $contribution->getProject() !== $project) {
            throw new NotFoundHttpException('Contribution not found');
        }

        $this->accessChecker->assertContributionOf($user, $contribution);

        $contributionId = $contribution->getId()->toRfc4122();
        $characterName = PilotName::of($contribution->getMember()->getUser());
        $bomItemName = $contribution->getBomItem()?->getTypeName();
        $quantity = $contribution->getQuantity();

        try {
            $this->contributionService->withdraw($contribution);
        } catch (\DomainException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'contribution_withdrawn',
            [
                'contributionId' => $contributionId,
                'characterName' => $characterName,
                'bomItemName' => $bomItemName,
                'quantity' => $quantity,
            ],
        );
    }
}
