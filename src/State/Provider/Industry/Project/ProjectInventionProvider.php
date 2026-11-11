<?php

declare(strict_types=1);

namespace App\State\Provider\Industry\Project;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\Project\ProjectInventionResource;
use App\Entity\Character;
use App\Entity\User;
use App\Repository\IndustryProjectRepository;
use App\Service\Industry\Project\ProjectInventionComparator;
use App\Service\UuidOrNotFound;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ProjectInventionResource>
 */
final readonly class ProjectInventionProvider implements ProviderInterface
{
    private const string CHARACTER_NOT_FOUND = 'Character not found';

    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private IndustryProjectRepository $projectRepository,
        private ProjectAccessChecker $accessChecker,
        private ProjectInventionComparator $comparator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProjectInventionResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $projectId = $uriVariables['projectId'] ?? null;
        if (null === $projectId) {
            throw new NotFoundHttpException('Project not found');
        }

        $project = $this->projectRepository->find(UuidOrNotFound::of($projectId, 'Project not found'));
        if (null === $project) {
            throw new NotFoundHttpException('Project not found');
        }

        $this->accessChecker->assertAcceptedMember($user, $project);

        $resource = new ProjectInventionResource();
        $resource->id = $projectId;
        $resource->products = $this->comparator->compare(
            $project,
            $this->inventorOf($user, $this->requestStack->getCurrentRequest()?->query->get('inventorCharacterId')),
        );

        return $resource;
    }

    private function inventorOf(User $user, mixed $rawCharacterId): ?Character
    {
        if (null === $rawCharacterId) {
            return null;
        }

        $characterId = UuidOrNotFound::of((string) $rawCharacterId, self::CHARACTER_NOT_FOUND)->toRfc4122();
        foreach ($user->getCharacters() as $character) {
            if ($character->getId()?->toRfc4122() === $characterId) {
                return $character;
            }
        }

        throw new NotFoundHttpException(self::CHARACTER_NOT_FOUND);
    }
}
