<?php

declare(strict_types=1);

namespace App\ApiResource\Market;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Market\CreateFavoriteInput;
use App\State\Processor\Market\AddFavoriteProcessor;
use App\State\Processor\Market\RemoveFavoriteProcessor;
use App\State\Provider\Market\MarketFavoriteCollectionProvider;
use App\State\Provider\Market\MarketFavoriteDeleteProvider;

#[ApiResource(
    shortName: 'MarketFavorite',
    description: 'User market favorites',
    operations: [
        new GetCollection(
            uriTemplate: '/market/favorites',
            provider: MarketFavoriteCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List favorites with prices', tags: ['Market']),
        ),
        new Get(
            uriTemplate: '/market/favorites/{typeId}',
            provider: MarketFavoriteDeleteProvider::class,
            openapi: new Model\Operation(summary: 'Get a favorite (used for IRI resolution)', tags: ['Market']),
        ),
        new Post(
            uriTemplate: '/market/favorites',
            input: CreateFavoriteInput::class,
            processor: AddFavoriteProcessor::class,
            openapi: new Model\Operation(tags: ['Market']),
        ),
        new Delete(
            uriTemplate: '/market/favorites/{typeId}',
            provider: MarketFavoriteDeleteProvider::class,
            processor: RemoveFavoriteProcessor::class,
            openapi: new Model\Operation(tags: ['Market']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MarketFavoriteResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    public string $typeName;

    public ?float $jitaSell = null;

    public ?float $jitaBuy = null;

    public ?float $change30d = null;

    public string $createdAt;
}
