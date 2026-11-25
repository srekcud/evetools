<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlannerAccountResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryPlannerAccountReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryPlannerAccountResource>
 */
final readonly class PlanetaryPlannerAccountProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryPlannerAccountReader $reader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlannerAccountResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        $account = $this->reader->read($user);

        $resource = new PlanetaryPlannerAccountResource();
        $resource->usedPlanets = $account['usedPlanets'];
        $resource->characters = $account['characters'];
        $resource->extractionYields = $account['extractionYields'];

        return $resource;
    }
}
