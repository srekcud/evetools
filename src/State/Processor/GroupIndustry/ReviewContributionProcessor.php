<?php

declare(strict_types=1);

namespace App\State\Processor\GroupIndustry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\GroupIndustry\GroupIndustryContributionResource;
use App\ApiResource\Input\GroupIndustry\ReviewContributionInput;
use App\Entity\User;
use App\Repository\GroupIndustryContributionRepository;
use App\Repository\GroupIndustryProjectRepository;
use App\Service\GroupIndustry\GroupIndustryContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\State\Provider\GroupIndustry\GroupIndustryResourceMapper;
use App\State\Provider\GroupIndustry\GroupProjectAccessChecker;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<ReviewContributionInput, GroupIndustryContributionResource>
 */
class ReviewContributionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly GroupIndustryProjectRepository $projectRepository,
        private readonly GroupIndustryContributionRepository $contributionRepository,
        private readonly GroupIndustryContributionService $contributionService,
        private readonly GroupProjectAccessChecker $accessChecker,
        private readonly GroupIndustryResourceMapper $mapper,
        private readonly MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GroupIndustryContributionResource
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

        $project = $this->projectRepository->find(Uuid::fromString($projectId));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        $contribution = $this->contributionRepository->find(Uuid::fromString($contributionId));
        if ($contribution === null || $contribution->getProject() !== $project) {
            throw new NotFoundHttpException('Contribution not found');
        }

        try {
            if ($data->status === 'approved') {
                $this->contributionService->approve($contribution, $user);
            } elseif ($data->status === 'rejected') {
                $this->contributionService->reject($contribution, $user);
            }
        } catch (\DomainException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $action = $data->status === 'approved' ? 'contribution_approved' : 'contribution_rejected';
        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            $action,
            [
                'contributionId' => $contribution->getId()->toRfc4122(),
                'reviewedBy' => $user->getMainCharacter()?->getName() ?? 'Unknown',
            ],
        );

        return $this->mapper->contributionToResource($contribution);
    }
}
