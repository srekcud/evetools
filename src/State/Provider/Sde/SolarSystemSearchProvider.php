<?php

declare(strict_types=1);

namespace App\State\Provider\Sde;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Sde\SolarSystemSearchResource;
use App\Entity\User;
use App\Repository\Sde\MapSolarSystemRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<SolarSystemSearchResource>
 */
class SolarSystemSearchProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly MapSolarSystemRepository $mapSolarSystemRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return list<SolarSystemSearchResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $request = $this->requestStack->getCurrentRequest();
        $query = $request?->query->get('q', '') ?? '';

        if (strlen($query) < 2) {
            return [];
        }

        $results = $this->mapSolarSystemRepository->searchByName($query, 10);

        return array_map(function (array $row) {
            $resource = new SolarSystemSearchResource();
            $resource->solarSystemId = (int) $row['solar_system_id'];
            $resource->solarSystemName = $row['solar_system_name'];
            $resource->security = round((float) $row['security'], 1);
            $resource->regionName = $row['region_name'];

            return $resource;
        }, $results);
    }
}
