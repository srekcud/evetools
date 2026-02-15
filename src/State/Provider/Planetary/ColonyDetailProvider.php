<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\ColonyResource;
use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<ColonyResource>
 */
class ColonyDetailProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly PlanetaryResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ColonyResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $id = $uriVariables['id'] ?? null;
        if ($id === null) {
            throw new NotFoundHttpException('Colony not found');
        }

        $colony = $this->colonyRepository->find(Uuid::fromString($id));
        if ($colony === null) {
            throw new NotFoundHttpException('Colony not found');
        }

        // Verify ownership: colony's character must belong to the current user
        $character = $colony->getCharacter();
        $characterId = $character->getId();
        $isOwner = false;
        if ($characterId !== null) {
            foreach ($user->getCharacters() as $userChar) {
                $userCharId = $userChar->getId();
                if ($userCharId !== null && $userCharId->equals($characterId)) {
                    $isOwner = true;
                    break;
                }
            }
        }

        if (!$isOwner) {
            throw new NotFoundHttpException('Colony not found');
        }

        return $this->mapper->toDetailResource($colony);
    }
}
