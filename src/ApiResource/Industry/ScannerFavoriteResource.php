<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\CreateScannerFavoriteInput;
use App\State\Processor\Industry\CreateScannerFavoriteProcessor;
use App\State\Processor\Industry\DeleteScannerFavoriteProcessor;
use App\State\Provider\Industry\ScannerFavoriteCollectionProvider;
use App\State\Provider\Industry\ScannerFavoriteDeleteProvider;
use App\State\Provider\Industry\ScannerFavoriteItemProvider;

#[ApiResource(
    shortName: 'ScannerFavorite',
    description: 'Scanner favorite items for quick access',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/scanner-favorites',
            provider: ScannerFavoriteCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List scanner favorites', tags: ['Industry - Scanner']),
        ),
        new Get(
            uriTemplate: '/industry/scanner-favorites/{typeId}',
            provider: ScannerFavoriteItemProvider::class,
            openapi: new Model\Operation(summary: 'Get scanner favorite', tags: ['Industry - Scanner']),
        ),
        new Post(
            uriTemplate: '/industry/scanner-favorites',
            processor: CreateScannerFavoriteProcessor::class,
            input: CreateScannerFavoriteInput::class,
            openapi: new Model\Operation(summary: 'Add scanner favorite', tags: ['Industry - Scanner']),
        ),
        new Delete(
            uriTemplate: '/industry/scanner-favorites/{typeId}',
            provider: ScannerFavoriteDeleteProvider::class,
            processor: DeleteScannerFavoriteProcessor::class,
            openapi: new Model\Operation(summary: 'Remove scanner favorite', tags: ['Industry - Scanner']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class ScannerFavoriteResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    public string $createdAt;
}
