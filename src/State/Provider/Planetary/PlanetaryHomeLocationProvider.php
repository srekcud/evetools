<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryHomeLocationResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryHomeLocationCatalog;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryHomeLocationResource>
 */
final readonly class PlanetaryHomeLocationProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryHomeLocationCatalog $catalog,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryHomeLocationResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        return new PlanetaryHomeLocationResource();
    }
}
