<?php

declare(strict_types=1);

namespace App\State\Processor\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\AssetItemNameResource;
use App\Repository\CachedAssetRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\ESI\AssetsService;
use App\Service\Sync\AssetsSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<mixed, AssetItemNameResource>
 */
class ResolveAssetItemNameProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private readonly AssetsSyncService $assetsSyncService,
        private readonly AssetsService $assetsService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AssetItemNameResource
    {
        $resource = new AssetItemNameResource();
        $resource->id = (string) ($uriVariables['id'] ?? '');

        return $resource;
    }
}
