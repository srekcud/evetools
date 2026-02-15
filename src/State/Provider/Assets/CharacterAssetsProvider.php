<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\AssetItemResource;
use App\ApiResource\Assets\CharacterAssetsResource;
use App\Entity\CachedAsset;
use App\Entity\User;
use App\Repository\CachedAssetRepository;
use App\Repository\CharacterRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProviderInterface<CharacterAssetsResource>
 */
class CharacterAssetsProvider implements ProviderInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CharacterRepository $characterRepository,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CharacterAssetsResource
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $uuid = Uuid::fromString($uriVariables['characterId']);
        $character = $this->characterRepository->find($uuid);

        if ($character === null || $character->getUser() !== $user) {
            throw new NotFoundHttpException('Character not found');
        }

        $request = $this->requestStack->getCurrentRequest();
        $locationId = $request?->query->get('locationId');

        if ($locationId !== null) {
            $assets = $this->cachedAssetRepository->findByCharacterAndLocation($character, (int) $locationId);
        } else {
            $assets = $this->cachedAssetRepository->findByCharacter($character);
        }

        $resource = new CharacterAssetsResource();
        $resource->characterId = $character->getId()?->toRfc4122() ?? '';
        $resource->total = \count($assets);
        $resource->items = array_map(fn (CachedAsset $asset) => $this->toItemResource($asset), $assets);

        return $resource;
    }

    private function toItemResource(CachedAsset $asset, bool $includeDivision = false): AssetItemResource
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
        $item->cachedAt = $asset->getCachedAt()->format('c');

        if ($includeDivision) {
            $item->divisionName = $asset->getDivisionName();
        }

        return $item;
    }
}
