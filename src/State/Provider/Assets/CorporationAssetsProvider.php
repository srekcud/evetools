<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\AssetItemResource;
use App\ApiResource\Assets\CorporationAssetsResource;
use App\Entity\CachedAsset;
use App\Entity\User;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\Sync\CorporationAssetsRefreshGate;
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
        private readonly VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private readonly InvTypeRepository $invTypeRepository,
        private readonly CorporationAssetsRefreshGate $refreshGate,
        private readonly RequestStack $requestStack,
        private readonly AssetBlueprintAnnotator $blueprintAnnotator,
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

        $assets = $this->visibleAssetsFinder->findVisibleAssets($corporationId, $divisionName, $user);

        // Resolve categoryId from SDE for each unique typeId
        $classification = $this->resolveTypeClassification($assets);

        $resource = new CorporationAssetsResource();
        $resource->total = count($assets);
        $resource->nextRefreshAt = $this->refreshGate->nextRefreshAt($corporationId)?->format('c');
        $resource->items = array_map(fn (CachedAsset $asset) => $this->toItemResource($asset, $classification), $assets);
        $this->blueprintAnnotator->annotateCorporationItems($corporationId, $resource->items);

        return $resource;
    }

    /**
     * @param array<int, array{categoryId: int, groupId: int}> $classification typeId => category and group
     */
    private function toItemResource(CachedAsset $asset, array $classification): AssetItemResource
    {
        $item = new AssetItemResource();
        $item->id = $asset->getId()?->toRfc4122() ?? '';
        $item->itemId = $asset->getItemId();
        $item->typeId = $asset->getTypeId();
        $item->typeName = $asset->getTypeName();
        $item->categoryId = $classification[$asset->getTypeId()]['categoryId'] ?? null;
        $item->quantity = $asset->getQuantity();
        $item->locationId = $asset->getLocationId();
        $item->locationName = $asset->getLocationName();
        $item->locationType = $asset->getLocationType();
        $item->locationFlag = $asset->getLocationFlag() ?? '';
        $item->solarSystemId = $asset->getSolarSystemId();
        $item->solarSystemName = $asset->getSolarSystemName();
        $item->itemName = $asset->getItemName();
        $item->isSingleton = $asset->isSingleton();
        $item->canResolveItemName = $asset->canResolveItemName(
            $item->categoryId,
            $classification[$asset->getTypeId()]['groupId'] ?? null,
        );
        $item->divisionName = $asset->getDivisionName();
        $item->cachedAt = $asset->getCachedAt()->format('c');

        return $item;
    }

    /**
     * Build a typeId => categoryId map from SDE data.
     *
     * @param CachedAsset[] $assets
     * @return array<int, array{categoryId: int, groupId: int}>
     */
    private function resolveTypeClassification(array $assets): array
    {
        $typeIds = array_unique(array_map(fn (CachedAsset $a) => $a->getTypeId(), $assets));

        if (empty($typeIds)) {
            return [];
        }

        $invTypes = $this->invTypeRepository->findByTypeIds($typeIds);
        $map = [];

        foreach ($invTypes as $typeId => $invType) {
            $map[$typeId] = [
                'categoryId' => $invType->getGroup()->getCategory()->getCategoryId(),
                'groupId' => $invType->getGroup()->getGroupId(),
            ];
        }

        return $map;
    }
}
