<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryProjectNeedsResource;
use App\Entity\User;
use App\Service\Planetary\Stock\PlanetaryProjectNeedsReader;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetaryProjectNeedsResource>
 */
final readonly class PlanetaryProjectNeedsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryProjectNeedsReader $reader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryProjectNeedsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        $this->reader->read($user);

        return new PlanetaryProjectNeedsResource();
    }
}
