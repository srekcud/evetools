<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlannerAccountResource;
use App\Service\Planetary\PlanetaryPlannerAccountReader;
use Symfony\Bundle\SecurityBundle\Security;

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
        return new PlanetaryPlannerAccountResource();
    }
}
