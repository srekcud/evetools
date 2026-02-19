<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\CorporationStructureProvider;

#[ApiResource(
    shortName: 'CorporationStructure',
    description: 'Corporation shared structures',
    operations: [
        new Get(
            uriTemplate: '/industry/corporation-structures',
            provider: CorporationStructureProvider::class,
            output: CorporationStructureListResource::class,
            openapi: new Model\Operation(summary: 'Get corporation structures', description: 'Returns structures shared by corporation members', tags: ['Industry - Structures']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CorporationStructureResource
{
    #[ApiProperty(identifier: true)]
    public int $locationId;

    public string $locationName;

    public ?int $solarSystemId = null;

    public ?string $solarSystemName = null;

    public bool $isCorporationOwned = false;

    public ?string $structureType = null;

    /** @var array<string, mixed>|null */
    public ?array $sharedConfig = null;
}
