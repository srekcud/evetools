<?php

declare(strict_types=1);

namespace App\State\Processor\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Assets\AssetItemNameResource;
use App\Entity\CachedAsset;
use App\Entity\EveToken;
use App\Entity\User;
use App\Exception\EsiApiException;
use App\Repository\CachedAssetRepository;
use App\Repository\Sde\InvTypeRepository;
use App\Service\Assets\VisibleCorporationAssetsFinder;
use App\Service\ESI\AssetsService;
use App\Service\Sync\AssetsSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<mixed, AssetItemNameResource>
 */
class ResolveAssetItemNameProcessor implements ProcessorInterface
{
    private const string STATUS_NAMED = 'named';
    private const string STATUS_UNNAMED = 'unnamed';
    private const int BAD_GATEWAY = 502;

    public function __construct(
        private readonly Security $security,
        private readonly CachedAssetRepository $cachedAssetRepository,
        private readonly VisibleCorporationAssetsFinder $visibleAssetsFinder,
        private readonly AssetsSyncService $assetsSyncService,
        private readonly AssetsService $assetsService,
        private readonly EntityManagerInterface $entityManager,
        private readonly InvTypeRepository $invTypeRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AssetItemNameResource
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Unauthorized');
        }

        $asset = $this->findAccessibleAsset($uriVariables['id'] ?? null, $user);
        $this->assertEligible($asset);

        $name = $this->fetchItemName($asset);

        $resource = new AssetItemNameResource();
        $resource->id = $asset->getId()?->toRfc4122() ?? throw new NotFoundHttpException('Asset not found');

        if ($name === null) {
            $resource->status = self::STATUS_UNNAMED;

            return $resource;
        }

        $asset->setItemName($name);
        $this->entityManager->flush();

        $resource->status = self::STATUS_NAMED;
        $resource->itemName = $name;

        return $resource;
    }

    private function findAccessibleAsset(mixed $assetId, User $user): CachedAsset
    {
        if (!is_string($assetId) || !Uuid::isValid($assetId)) {
            throw $this->assetNotFound();
        }

        $asset = $this->cachedAssetRepository->find(Uuid::fromString($assetId));
        if ($asset === null) {
            throw $this->assetNotFound();
        }

        $isAccessible = $asset->isCorporationAsset()
            ? $this->isVisibleCorporationAsset($asset, $user)
            : $asset->getCharacter()?->getUser() === $user;

        if (!$isAccessible) {
            throw $this->assetNotFound();
        }

        return $asset;
    }

    private function isVisibleCorporationAsset(CachedAsset $asset, User $user): bool
    {
        $mainCharacter = $user->getMainCharacter();
        if ($mainCharacter === null) {
            throw new AccessDeniedHttpException('No main character set');
        }

        $corporationId = $mainCharacter->getCorporationId();
        if ($asset->getCorporationId() !== $corporationId) {
            return false;
        }

        $assetId = $asset->getId()?->toRfc4122();
        foreach ($this->visibleAssetsFinder->findVisibleAssets($corporationId, null, $user) as $visibleAsset) {
            if ($assetId !== null && $visibleAsset->getId()?->toRfc4122() === $assetId) {
                return true;
            }
        }

        return false;
    }

    private function assertEligible(CachedAsset $asset): void
    {
        if ($asset->canResolveItemName($this->categoryIdOf($asset))) {
            return;
        }

        $reason = match (true) {
            $asset->isSingleton() === null => 'Singleton flag unknown, refresh the assets',
            $asset->isSingleton() === false => 'This asset is not a singleton, it cannot have a custom name',
            $asset->getItemName() !== null => 'This asset is already named',
            default => 'An office cannot be named',
        };

        throw new UnprocessableEntityHttpException($reason);
    }

    private function categoryIdOf(CachedAsset $asset): ?int
    {
        $invType = $this->invTypeRepository->findByTypeIds([$asset->getTypeId()])[$asset->getTypeId()] ?? null;

        return $invType?->getGroup()->getCategory()->getCategoryId();
    }

    private function fetchItemName(CachedAsset $asset): ?string
    {
        if ($asset->isCorporationAsset()) {
            $corporationId = $asset->getCorporationId();
            if ($corporationId === null) {
                throw new ConflictHttpException('The corporation of this asset is unknown');
            }

            $director = $this->assetsSyncService->getCorpAssetsCharacter($corporationId);
            if ($director === null) {
                throw new ConflictHttpException('No Director connected. A Director of the corporation must link a character with the corporation assets and roles scopes.');
            }

            return $this->requestName($asset, $director->getEveToken(), null, $corporationId);
        }

        $character = $asset->getCharacter();
        if ($character === null) {
            throw $this->assetNotFound();
        }

        return $this->requestName($asset, $character->getEveToken(), $character->getEveCharacterId(), null);
    }

    private function requestName(CachedAsset $asset, ?EveToken $token, ?int $characterId, ?int $corporationId): ?string
    {
        if ($token === null) {
            throw new ConflictHttpException('No ESI token available for the owner of this asset, link the character again');
        }

        try {
            return $this->assetsService->resolveSingleItemName($asset->getItemId(), $token, $characterId, $corporationId);
        } catch (EsiApiException $e) {
            throw new HttpException(self::BAD_GATEWAY, $e->getMessage(), $e);
        }
    }

    private function assetNotFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('Asset not found');
    }
}
