<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectBomLineMarkResource;
use App\Entity\User;
use App\Repository\IndustryProjectBomLineRepository;
use App\Repository\IndustryProjectRepository;
use App\Service\Mercure\MercurePublisherService;
use App\Service\UuidOrNotFound;
use App\State\Provider\Industry\Project\ProjectAccessChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProcessorInterface<ProjectBomLineMarkResource, void>
 */
final readonly class RemoveBomLineMarkProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectBomLineRepository $bomLineRepository,
        private ProjectAccessChecker $accessChecker,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertOwner($user, $project);

        $line = $this->bomLineRepository->find(UuidOrNotFound::of($uriVariables['lineId'] ?? null, 'BOM line not found'));
        if (null === $line || $line->getProject() !== $project) {
            throw new NotFoundHttpException('BOM line not found');
        }

        if ($project->isCompleted()) {
            throw new BadRequestHttpException('The project is completed, the marks of its lines can no longer be removed');
        }

        $line->setMark(null);
        $this->entityManager->flush();

        BomLineMarkNotifier::notifyLineChanged($this->mercurePublisher, $project, $line);
    }
}
