<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetarySettingsResource;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<PlanetarySettingsResource>
 */
final readonly class PlanetarySettingsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetarySettingsResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required');
        }

        return new PlanetarySettingsResource();
    }
}
