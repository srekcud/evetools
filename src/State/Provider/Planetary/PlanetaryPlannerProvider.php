<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlannerResource;
use App\Service\Planetary\PlanetaryPlannerCatalog;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<PlanetaryPlannerResource>
 */
final readonly class PlanetaryPlannerProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryPlannerCatalog $catalog,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlannerResource
    {
        return new PlanetaryPlannerResource();
    }
}
