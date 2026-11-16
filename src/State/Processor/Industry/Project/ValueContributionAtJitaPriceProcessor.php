<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectContributionResource;
use App\ApiResource\Input\EmptyInput;
use App\Entity\User;
use App\Repository\IndustryProjectContributionRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectContributionService;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectResourceMapper;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<EmptyInput, ProjectContributionResource>
 */
final readonly class ValueContributionAtJitaPriceProcessor implements ProcessorInterface
{
    private const string CONTRIBUTION_VALUED_ACTION = 'contribution_valued';

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectContributionRepository $contributionRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectContributionService $contributionService,
        private ProjectResourceMapper $mapper,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectContributionResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of((string) ($uriVariables['projectId'] ?? ''), 'Project not found'));
        if ($project === null) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $contribution = $this->contributionRepository->find(UuidOrNotFound::of((string) ($uriVariables['id'] ?? ''), 'Contribution not found'));
        if ($contribution === null || $contribution->getProject() !== $project) {
            throw new NotFoundHttpException('Contribution not found');
        }

        if (!$contribution->isContributedBy($user)) {
            throw new AccessDeniedHttpException('Only the contributor can value the contribution at the Jita price');
        }

        try {
            $this->contributionService->valueAtJitaPrice($contribution);
        } catch (\DomainException $refusal) {
            throw new BadRequestHttpException($refusal->getMessage());
        }

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            self::CONTRIBUTION_VALUED_ACTION,
            [
                'contributionId' => $contribution->getId()->toRfc4122(),
                'bomItemId' => $contribution->getBomItem()?->getId()?->toRfc4122(),
            ],
        );

        return $this->mapper->contributionToResource($contribution, $user);
    }
}
