<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\ManufacturableTypeProvider;

#[ApiResource(
    shortName: 'ManufacturableType',
    description: 'A manufacturable product opened by its type identifier',
    operations: [
        new Get(
            uriTemplate: '/industry/types/{typeId}',
            provider: ManufacturableTypeProvider::class,
            openapi: new Model\Operation(
                summary: 'Get a manufacturable product',
                description: 'Returns the name and the T2 flag of a manufacturable product by type identifier',
                tags: ['Industry - Configuration'],
                parameters: [
                    new Model\Parameter(name: 'typeId', in: 'path', required: true, schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ManufacturableTypeResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    public string $typeName;

    public bool $isT2 = false;
}
