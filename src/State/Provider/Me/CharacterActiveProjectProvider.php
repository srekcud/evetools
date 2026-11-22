<?php

declare(strict_types=1);

namespace App\State\Provider\Me;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Me\CharacterActiveProjectResource;
use App\Entity\User;
use App\Repository\CharacterRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<CharacterActiveProjectResource>
 */
class CharacterActiveProjectProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CharacterActiveProjectResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $characterId = (string) $uriVariables['id'];

        if (!Uuid::isValid($characterId)) {
            throw new NotFoundHttpException('Character not found');
        }

        $character = $this->characterRepository->find($characterId);

        if ($character === null || $character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        $project = $character->getActiveProject();
        $resource = new CharacterActiveProjectResource();
        $resource->id = $characterId;
        $resource->activeProjectId = $project?->getId()->toRfc4122();
        $resource->activeProjectName = $project?->getName();

        return $resource;
    }
}
