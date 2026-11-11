<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectInventionProvider;

#[ApiResource(
    shortName: 'ProjectInvention',
    description: 'Decryptor comparison of the invented final products of an industry project, at the units, ME and TE of the project',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/invention',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectInventionProvider::class,
            openapi: new Model\Operation(
                summary: 'Compare the decryptors of each invented final product of the project',
                description: 'Returns one entry per T2 final product of the project with the invention options (probability, runs, ME, TE, attempts, jobs, costs) for the chosen inventor; nothing is persisted and the project plan is unchanged',
                tags: ['Group Industry - Invention'],
                parameters: [
                    new Model\Parameter(name: 'inventorCharacterId', in: 'query', required: false, schema: ['type' => 'string', 'format' => 'uuid']),
                ],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectInventionResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /**
     * @var list<array<string, mixed>>
     */
    public array $products = [];
}
