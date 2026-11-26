<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlannerMarketResource;
use App\Constant\EveConstants;
use App\Entity\User;
use App\Service\Planetary\PlanetaryMarketStatsReader;
use App\Service\Planetary\PlanetaryProductTypeResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

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
        if (!$this->security->getUser() instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $resource = new PlanetaryPlannerMarketResource();
        $resource->regionId = EveConstants::THE_FORGE_REGION_ID;
        $resource->products = $this->statsReader->statsFor($this->typeResolver->factoryOutputTypeIds(), EveConstants::THE_FORGE_REGION_ID);

        return $resource;
    }
}
