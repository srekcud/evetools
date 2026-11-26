<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlannerMarketResource;
use App\Service\Planetary\PlanetaryMarketStatsReader;
use App\Service\Planetary\PlanetaryProductTypeResolver;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<PlanetaryPlannerMarketResource>
 */
final readonly class PlanetaryPlannerMarketProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlanetaryProductTypeResolver $typeResolver,
        private PlanetaryMarketStatsReader $statsReader,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlannerMarketResource
    {
        return new PlanetaryPlannerMarketResource();
    }
}
