<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlannerResource;
use App\Entity\User;
use App\Service\Planetary\PlanetaryPlannerCatalog;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

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
        if (!$this->security->getUser() instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $catalog = $this->catalog->build();

        $resource = new PlanetaryPlannerResource();
        $resource->recipes = $catalog['recipes'];
        $resource->types = $catalog['types'];
        $resource->prices = $catalog['prices'];
        $resource->planetTypes = $catalog['planetTypes'];
        $resource->extractionPlanetTypes = $catalog['extractionPlanetTypes'];

        return $resource;
    }
}
