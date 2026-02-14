<?php

declare(strict_types=1);

namespace App\ApiResource\Assets;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Assets\CorporationAssetsStatusProvider;

#[ApiResource(
    shortName: 'CorporationAssetsStatus',
    description: 'Corporation assets access status',
    operations: [
        new Get(
            uriTemplate: '/me/corporation/assets/status',
            provider: CorporationAssetsStatusProvider::class,
            openapi: new Model\Operation(summary: 'Get corporation assets access status', description: 'Returns whether the user has access to corporation assets'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CorporationAssetsStatusResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'status';

    public bool $hasAccess = false;

    public ?string $accessCharacterName = null;

    public int $corporationId;

    public string $corporationName;
}
