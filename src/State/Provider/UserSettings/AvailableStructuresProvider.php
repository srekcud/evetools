<?php

declare(strict_types=1);

namespace App\State\Provider\UserSettings;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserSettings\AvailableStructureResource;
use App\Entity\User;
use App\Repository\IndustryStructureConfigRepository;
use App\Service\StructureMarketService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<AvailableStructureResource>
 */
class AvailableStructuresProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly StructureMarketService $structureMarketService,
        private readonly IndustryStructureConfigRepository $structureConfigRepository,
        private readonly int $defaultMarketStructureId,
        private readonly string $defaultMarketStructureName,
    ) {
    }

    /** @return AvailableStructureResource[] */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $structures = [];

        // 1. Default structure from app config
        $default = new AvailableStructureResource();
        $default->structureId = $this->defaultMarketStructureId;
        $default->structureName = $this->defaultMarketStructureName;
        $default->isDefault = true;
        $default->hasCachedData = $this->structureMarketService->hasCachedData($this->defaultMarketStructureId);
        $default->lastSyncAt = $this->structureMarketService->getLastSyncTime($this->defaultMarketStructureId)?->format('c');
        $structures[$this->defaultMarketStructureId] = $default;

        // 2. Industry structure configs with a locationId (player structures)
        $configs = $this->structureConfigRepository->findBy(['user' => $user]);
        foreach ($configs as $config) {
            $locationId = $config->getLocationId();
            if ($locationId === null || isset($structures[$locationId])) {
                continue;
            }

            $resource = new AvailableStructureResource();
            $resource->structureId = $locationId;
            $resource->structureName = $config->getName();
            $resource->isDefault = false;
            $resource->hasCachedData = $this->structureMarketService->hasCachedData($locationId);
            $resource->lastSyncAt = $this->structureMarketService->getLastSyncTime($locationId)?->format('c');
            $structures[$locationId] = $resource;
        }

        return array_values($structures);
    }
}
