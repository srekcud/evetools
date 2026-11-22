<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectStationTransferResource;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Repository\IndustryProjectStationTransferRepository;
use App\Service\Industry\Project\ProjectContainerReadings;
use App\Service\Industry\Project\Transfer\ProjectStationTransfers;
use App\Service\Industry\Project\Transfer\StationTransferReading;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use App\State\Provider\Industry\Project\ProjectStationTransferResourceMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<mixed, ProjectStationTransferResource>
 */
final readonly class ArriveStationTransferProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectStationTransferRepository $transferRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectContainerReadings $containerReadings,
        private ProjectStationTransfers $transfers,
        private ProjectStationTransferResourceMapper $mapper,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectStationTransferResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $leg = $this->transferRepository->findOneByProjectAndId($project, UuidOrNotFound::of($uriVariables['id'] ?? null, 'Transfer not found'))
            ?? throw new NotFoundHttpException('Transfer not found');

        if ($leg->getDeclaredBy() !== $user && !$this->accessChecker->isAdminOrOwner($user, $project)) {
            throw new AccessDeniedHttpException('Only the declarer, the project owner or an admin can change this transfer');
        }

        if (null !== $leg->getArrivedAt()) {
            throw new ConflictHttpException('The transfer already arrived');
        }

        if ($leg->getLegIndex() > 0) {
            $legBefore = $this->transferRepository->findByRoute($leg->getRouteId())[$leg->getLegIndex() - 1] ?? null;
            if (null === $legBefore || null === $legBefore->getArrivedAt()) {
                throw new ConflictHttpException('The leg before this one has not arrived');
            }
        }

        $leg->markArrived(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'station_transfer_changed',
            ['routeId' => $leg->getRouteId()->toRfc4122(), 'legIndex' => $leg->getLegIndex(), 'action' => 'arrived'],
        );

        $readings = array_values(array_filter(
            $this->transfers->of($project, $this->containerReadings->of($project)),
            static fn (StationTransferReading $reading): bool => $reading->leg === $leg,
        ));

        return $this->mapper->toResources($project, $readings)[0] ?? throw new \LogicException('The arrived leg is read back.');
    }
}
