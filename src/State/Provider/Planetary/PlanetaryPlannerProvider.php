<?php

declare(strict_types=1);

namespace App\State\Provider\Planetary;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Planetary\PlanetaryPlannerResource;
use App\Entity\User;
use App\ApiResource\Planetary\PlanetarySettingsResource;
use App\Service\Planetary\PlanetaryPlannerCatalog;
use App\Service\Planetary\PlanetaryPlannerUserPrices;
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
        private PlanetaryPlannerUserPrices $userPrices,
        private int $defaultMarketStructureId,
        private string $defaultMarketStructureName,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlanetaryPlannerResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $catalog = $this->catalog->build();
        $hub = PlanetarySettingsResource::forUser($user, $this->defaultMarketStructureId, $this->defaultMarketStructureName);
        $userPrices = $this->userPrices->read($user, array_column($catalog['prices'], 'typeId'), $hub->hubStructureId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $resource = new PlanetaryPlannerResource();
        $resource->recipes = $catalog['recipes'];
        $resource->types = $catalog['types'];
        $resource->prices = array_map(
            static fn (array $price): array => [...$price, ...$userPrices['prices'][$price['typeId']]],
            $catalog['prices'],
        );
        $resource->hub = ['structureId' => $hub->hubStructureId, 'structureName' => $hub->hubStructureName, 'hasData' => $userPrices['hasHubData']];
        $resource->planetTypes = $catalog['planetTypes'];
        $resource->extractionPlanetTypes = $catalog['extractionPlanetTypes'];

        return $resource;
    }
}
