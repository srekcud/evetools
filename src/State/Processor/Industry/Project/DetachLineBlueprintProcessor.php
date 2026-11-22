<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectLineBlueprintResource;
use App\Entity\User;
use App\Repository\IndustryProjectLineBlueprintRepository;
use App\Repository\IndustryProjectRepository;
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
 * @implements ProcessorInterface<ProjectLineBlueprintResource, void>
 */
final readonly class DetachLineBlueprintProcessor implements ProcessorInterface
{
    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private IndustryProjectLineBlueprintRepository $lineBlueprintRepository,
        private ProjectAccessChecker $accessChecker,
        private EntityManagerInterface $entityManager,
        private MercurePublisherService $mercurePublisher,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        \assert($data instanceof ProjectLineBlueprintResource);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $attachment = $this->lineBlueprintRepository->findOneByProjectAndId($project, UuidOrNotFound::of($data->id, 'Blueprint not found'))
            ?? throw new NotFoundHttpException('Blueprint not found');

        if ($attachment->getAttachedBy() !== $user && !$this->accessChecker->isAdminOrOwner($user, $project)) {
            throw new AccessDeniedHttpException('Only the attacher, the project owner or an admin can detach this blueprint');
        }

        if ($project->isCompleted()) {
            throw new ConflictHttpException('The project is completed, its step blueprints can no longer change');
        }

        $lineId = $attachment->getBomLine()->getId()->toRfc4122();
        $itemId = $attachment->getItemId();
        $attachment->getBomLine()->getBlueprints()->removeElement($attachment);
        $this->entityManager->remove($attachment);
        $this->entityManager->flush();

        $this->mercurePublisher->publishGroupProjectEvent(
            $project->getId()->toRfc4122(),
            'line_blueprint_changed',
            ['lineId' => $lineId, 'itemId' => $itemId, 'action' => 'detached'],
        );
    }
}
