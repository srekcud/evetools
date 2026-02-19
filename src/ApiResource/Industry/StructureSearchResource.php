<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\StructureSearchProvider;

#[ApiResource(
    shortName: 'StructureSearch',
    description: 'Search structures via ESI',
    operations: [
        new Get(
            uriTemplate: '/industry/search-structure',
            provider: StructureSearchProvider::class,
            output: StructureSearchListResource::class,
            openapi: new Model\Operation(
                summary: 'Search structures',
                description: 'Search for structures by name via ESI',
                tags: ['Industry - Structures'],
                parameters: [
                    new Model\Parameter(name: 'q', in: 'query', required: true, schema: ['type' => 'string', 'minLength' => 3]),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class StructureSearchResource
{
    #[ApiProperty(identifier: true)]
    public int $locationId;

    public string $locationName;

    public ?int $solarSystemId = null;

    public ?string $solarSystemName = null;

    public ?string $structureType = null;

    public ?int $typeId = null;

    public bool $isCorporationOwned = false;
}
