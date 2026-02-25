<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\PivotAdvisorProvider;

#[ApiResource(
    shortName: 'IndustryPivotAdvisor',
    description: 'Pivot Advisor - find alternative products using shared components',
    operations: [
        new Get(
            uriTemplate: '/industry/pivot-advisor/{typeId}',
            provider: PivotAdvisorProvider::class,
            openapi: new Model\Operation(
                summary: 'Analyze pivot options',
                description: 'Find alternative products sharing components with the source product',
                tags: ['Industry - Scanner'],
                parameters: [
                    new Model\Parameter(name: 'typeId', in: 'path', required: true, schema: ['type' => 'integer']),
                    new Model\Parameter(name: 'runs', in: 'query', required: false, schema: ['type' => 'integer', 'default' => 1]),
                    new Model\Parameter(name: 'solarSystemId', in: 'query', required: false, schema: ['type' => 'integer']),
                ],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PivotAdvisorResource
{
    #[ApiProperty(identifier: true)]
    public int $typeId;

    public array $sourceProduct = [];

    public array $matrix = [];

    public array $candidates = [];

    /** @var list<int> */
    public array $matrixProductIds = [];
}
