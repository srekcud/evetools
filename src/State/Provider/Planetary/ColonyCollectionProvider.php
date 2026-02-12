<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\ColonyResource;
use App\Entity\PlanetaryColony;
use App\Entity\User;
use App\Repository\PlanetaryColonyRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<ColonyResource>
 */
class ColonyCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PlanetaryColonyRepository $colonyRepository,
        private readonly PlanetaryResourceMapper $mapper,
    ) {
    }

    /**
     * @return ColonyResource[]
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $colonies = $this->colonyRepository->findByUser($user);

        return array_map(fn (PlanetaryColony $c) => $this->mapper->toResource($c), $colonies);
    }
}
