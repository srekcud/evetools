<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\AssetItemResource;
use App\ApiResource\Assets\CorporationAssetsResource;
use App\Entity\CachedAsset;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CorpAssetVisibilityRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * @implements ProviderInterface<CorporationAssetsResource>
 */
class CorporationAssetsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly CorpAssetVisibilityRepository $visibilityRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CorporationAssetsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $corporationId = $mainCharacter->getCorporationId();
        $request = $this->requestStack->getCurrentRequest();
        $divisionName = $request?->query->get('divisionName');

        $visibility = $this->visibilityRepository->findByCorporationId($corporationId);
        $visibleDivisions = $visibility?->getVisibleDivisions();

        if ($divisionName !== null && $visibleDivisions !== null) {
            // Both division name filter and visibility config: apply both constraints
            $assets = $this->cachedAssetRepository->findByCorporationDivisionNameAndFlags(
                $corporationId,
                $divisionName,
                $visibleDivisions,
            );
        } elseif ($divisionName !== null) {
            $assets = $this->cachedAssetRepository->findByCorporationAndDivision($corporationId, $divisionName);
        } elseif ($visibleDivisions !== null) {
            $assets = $this->cachedAssetRepository->findByCorporationAndDivisions($corporationId, $visibleDivisions);
        } else {
            $assets = $this->cachedAssetRepository->findByCorporationId($corporationId);
        }

        $resource = new CorporationAssetsResource();
        $resource->total = count($assets);
        $resource->items = array_map(fn (CachedAsset $asset) => $this->toItemResource($asset), $assets);

        return $resource;
    }

    private function toItemResource(CachedAsset $asset): AssetItemResource
    {
        $item = new AssetItemResource();
        $item->id = $asset->getId()?->toRfc4122() ?? '';
        $item->itemId = $asset->getItemId();
        $item->typeId = $asset->getTypeId();
        $item->typeName = $asset->getTypeName();
        $item->quantity = $asset->getQuantity();
        $item->locationId = $asset->getLocationId();
        $item->locationName = $asset->getLocationName();
        $item->locationType = $asset->getLocationType();
        $item->locationFlag = $asset->getLocationFlag() ?? '';
        $item->solarSystemId = $asset->getSolarSystemId();
        $item->solarSystemName = $asset->getSolarSystemName();
        $item->itemName = $asset->getItemName();
        $item->divisionName = $asset->getDivisionName();
        $item->cachedAt = $asset->getCachedAt()->format('c');

        return $item;
    }
}
