<?php

declare(strict_types=1);

namespace App\State\Provider\Industry;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Industry\CorporationStructureListResource;
use App\ApiResource\Industry\CorporationStructureResource;
use App\Entity\User;
use App\Repository\CachedStructureRepository;
use App\Repository\IndustryStructureConfigRepository;
use App\Repository\Sde\MapSolarSystemRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<CorporationStructureListResource>
 */
class CorporationStructureProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly CachedStructureRepository $cachedStructureRepository,
        private readonly MapSolarSystemRepository $solarSystemRepository,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorporationStructureListResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $result = new CorporationStructureListResource();
        $corporationId = $user->getCorporationId();

        if ($corporationId === null) {
            return $result;
        }

        $sharedConfigs = $this->structureConfigRepository->findCorporationSharedStructures($corporationId, $user);

        if (empty($sharedConfigs)) {
            return $result;
        }

        $existingConfigs = $this->structureConfigRepository->findByUser($user);
        $existingLocationIds = [];
        foreach ($existingConfigs as $config) {
            $locId = $config->getLocationId();
            if ($locId !== null) {
                $existingLocationIds[$locId] = true;
            }
        }

        $structures = [];
        foreach ($sharedConfigs as $locationId => $config) {
            if (isset($existingLocationIds[$locationId])) {
                continue;
            }

            $resource = new CorporationStructureResource();
            $resource->locationId = $locationId;
            $resource->locationName = $config->getName();
            $resource->isCorporationOwned = true;
            $resource->structureType = $config->getStructureType();
            $resource->sharedConfig = [
                'securityType' => $config->getSecurityType(),
                'structureType' => $config->getStructureType(),
                'rigs' => $config->getRigs(),
                'manufacturingMaterialBonus' => $config->getManufacturingMaterialBonus(),
                'reactionMaterialBonus' => $config->getReactionMaterialBonus(),
                'manufacturingTimeBonus' => $config->getManufacturingTimeBonus(),
                'reactionTimeBonus' => $config->getReactionTimeBonus(),
            ];

            $structures[$locationId] = $resource;
        }

        if (!empty($structures)) {
            $cachedStructures = $this->cachedStructureRepository->findByStructureIds(array_keys($structures));
            foreach ($cachedStructures as $structureId => $cached) {
                if (isset($structures[$structureId])) {
                    $solarSystemId = $cached->getSolarSystemId();
                    $structures[$structureId]->solarSystemId = $solarSystemId;

                    if ($solarSystemId !== null) {
                        $solarSystem = $this->solarSystemRepository->findBySolarSystemId($solarSystemId);
                        $structures[$structureId]->solarSystemName = $solarSystem?->getSolarSystemName();
                    }
                }
            }
        }

        usort($structures, fn ($a, $b) => strcasecmp($a->locationName ?? '', $b->locationName ?? ''));

        $result->structures = $structures;

        return $result;
    }
}
