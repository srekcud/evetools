<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\PackagedVolumesResource;
use App\Constant\EveConstants;
use App\Repository\TypePackagedVolumeRepository;

/**
 * @implements ProviderInterface<PackagedVolumesResource>
 */
final readonly class PackagedVolumesProvider implements ProviderInterface
{
    public function __construct(private TypePackagedVolumeRepository $packagedVolumes)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PackagedVolumesResource
    {
        $resource = new PackagedVolumesResource();
        $resource->volumes = $this->packagedVolumes->findAllByTypeId();
        $resource->excludedTypeIds = EveConstants::STATION_CONTAINER_TYPE_IDS;
        $resource->refreshedAt = $this->packagedVolumes->findLatestRefreshedAt()?->format(\DateTimeInterface::ATOM);

        return $resource;
    }
}
