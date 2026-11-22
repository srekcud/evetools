<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectStationTransferResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStationTransferRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ProjectStationTransferResource, void>
 */
final readonly class DeleteStationTransferProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectStationTransferRepository $transferRepository,
        private ProjectAccessChecker $accessChecker,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        \assert($data instanceof ProjectStationTransferResource);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $leg = $this->transferRepository->findOneByProjectAndId($project, UuidOrNotFound::of($data->id, 'Transfer not found'))
            ?? throw new NotFoundHttpException('Transfer not found');

        if ($leg->getDeclaredBy() !== $user && !$this->accessChecker->isAdminOrOwner($user, $project)) {
            throw new AccessDeniedHttpException('Only the declarer, the project owner or an admin can change this transfer');
        }

        if ($project->isCompleted()) {
            throw new ConflictHttpException('The project is completed, its transfers can no longer change');
        }

        if (null !== $leg->getArrivedAt()) {
            throw new ConflictHttpException('A transfer that arrived is never deleted');
        }

        foreach ($this->transferRepository->findByRoute($leg->getRouteId()) as $routeLeg) {
            if ($routeLeg->getLegIndex() >= $leg->getLegIndex()) {
                $this->entityManager->remove($routeLeg);
            }
        }
        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'station_transfer_changed',
            ['routeId' => $leg->getRouteId()->toRfc4122(), 'legIndex' => $leg->getLegIndex(), 'action' => 'deleted'],
        );
    }
}
