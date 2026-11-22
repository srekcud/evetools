<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectSurplusResource;
use App\Entity\User;
use App\Enum\ProjectStatus;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectSurplusBuilder;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectSurplusResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, ProjectSurplusResource>
 */
final readonly class StoreSurplusProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectSurplusBuilder $surplusBuilder,
        private ProjectSurplusResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectSurplusResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'))
            ?? throw new NotFoundHttpException('Project not found');

        $this->accessChecker->assertAcceptedMember($user, $project);

        if (!$this->accessChecker->isAdminOrOwner($user, $project)) {
            throw new AccessDeniedHttpException('Only the project owner or an admin can store the surplus');
        }

        if (!in_array($project->getStatus(), [ProjectStatus::Selling, ProjectStatus::Completed], true)) {
            throw new ConflictHttpException('The surplus can be stored once the project is selling or completed');
        }

        if ([] === $this->surplusBuilder->build($project)->lines) {
            throw new ConflictHttpException('The project has no surplus to store');
        }

        if (null !== $project->getSurplusStoredAt()) {
            throw new ConflictHttpException('The surplus is already stored');
        }

        $project->markSurplusStored($user, new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent($project->getId()->toRfc4122(), 'surplus_stored', []);

        return $this->mapper->toResource($project);
    }
}
