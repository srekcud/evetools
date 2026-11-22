<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectContainerStationResource;
use App\ApiResource\Input\Industry\Project\PlaceProjectContainerInput;
use App\Entity\User;
use App\Exception\ContainerNameTakenException;
use App\Exception\LocationNotInPlanException;
use App\Exception\StationAlreadyHasContainerException;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectContainerPlacer;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * @implements ProcessorInterface<PlaceProjectContainerInput, ProjectContainerStationResource>
 */
final readonly class PlaceProjectContainerProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectContainerPlacer $placer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectContainerStationResource
    {
        \assert($data instanceof PlaceProjectContainerInput);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAdminOrOwner($user, $project);

        if ($project->isCompleted()) {
            throw new ConflictHttpException('The project is completed, its containers can no longer change');
        }

        $locationId = $data->locationId;
        if (!\is_int($locationId)) {
            throw new \LogicException('The validated input carries an integer location.');
        }

        try {
            $station = $this->placer->place($project, $locationId, $data->name);
        } catch (LocationNotInPlanException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        } catch (StationAlreadyHasContainerException|ContainerNameTakenException $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new ConflictHttpException('The station or the name was taken concurrently, retry', $exception);
        }

        return ProjectContainerStationResource::fromStation($station);
    }
}
