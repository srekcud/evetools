<?php

declare(strict_types=1);

namespace App\State\Provider\Assets;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Assets\AssetBrowseResource;
use App\ApiResource\Assets\CharacterAssetSearchResource;
use App\ApiResource\Assets\CorporationAssetSearchResource;
use App\Repository\AssetBrowseRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * @implements ProviderInterface<AssetBrowseResource>
 */
final readonly class AssetSearchProvider implements ProviderInterface
{
    private const int MIN_QUERY_LENGTH = 2;
    private const int MAX_HITS = 200;

    public function __construct(
        private AssetBrowseOwnerResolver $ownerResolver,
        private AssetBrowseRepository $repository,
        private AssetBrowseItemMapper $itemMapper,
        private RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CharacterAssetSearchResource|CorporationAssetSearchResource
    {
        $owner = $this->ownerResolver->resolve($uriVariables);
        $rawQuery = $this->requestStack->getCurrentRequest()?->query->get('q');
        $query = \is_string($rawQuery) ? trim($rawQuery) : '';
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            throw new BadRequestHttpException(\sprintf('The search needs at least %d characters', self::MIN_QUERY_LENGTH));
        }

        $result = $this->repository->search($owner->scope, $query, self::MAX_HITS);

        if ($owner->corporationId === null) {
            $resource = new CharacterAssetSearchResource();
            $resource->characterId = $owner->scope->characterId ?? throw new \LogicException('A character scope carries its id');
        } else {
            $resource = new CorporationAssetSearchResource();
        }

        $resource->query = $query;
        $resource->truncated = $result['truncated'];
        $resource->locationIds = $result['locationIds'];
        $resource->hits = $this->itemMapper->hits($owner, $result['hits']);

        return $resource;
    }
}
