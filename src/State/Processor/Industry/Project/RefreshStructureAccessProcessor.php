<?php

declare(strict_types=1);

namespace App\State\Processor\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Industry\Project\ProjectStructureAccessRefreshResource;
use App\Entity\User;
use App\Message\RefreshProjectStructureAccess;
use App\Repository\IndustryProjectRepository;
use App\Service\UuidOrNotFound;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<mixed, ProjectStructureAccessRefreshResource>
 */
final readonly class RefreshStructureAccessProcessor implements ProcessorInterface
{
    private const string COOLDOWN_KEY_PREFIX = 'structure_access_refresh_';
    private const int COOLDOWN_SECONDS = 300;

    public function __construct(
        private Security $security,
        private IndustryProjectRepository $projectRepository,
        private MessageBusInterface $messageBus,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProjectStructureAccessRefreshResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($uriVariables['projectId'] ?? null, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        if (!$project->isAcceptedMember($user)) {
            throw new AccessDeniedHttpException('You are not a member of this project');
        }
        if (!$project->isManagedBy($user)) {
            throw new AccessDeniedHttpException('Only the project owner or an admin can refresh the structure access');
        }

        $projectId = $project->getId()->toRfc4122();
        $cooldown = $this->cache->getItem(self::COOLDOWN_KEY_PREFIX.$projectId);
        if ($cooldown->isHit()) {
            throw new TooManyRequestsHttpException(self::COOLDOWN_SECONDS, 'A refresh of the structure access was queued less than five minutes ago');
        }

        $this->messageBus->dispatch(new RefreshProjectStructureAccess($projectId));

        $cooldown->set(true)->expiresAfter(self::COOLDOWN_SECONDS);
        $this->cache->save($cooldown);

        $resource = new ProjectStructureAccessRefreshResource();
        $resource->projectId = $projectId;

        return $resource;
    }
}
