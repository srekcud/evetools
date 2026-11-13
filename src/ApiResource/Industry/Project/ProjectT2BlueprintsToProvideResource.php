<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\State\Provider\Industry\Project\ProjectT2BlueprintsToProvideProvider;

#[ApiResource(
    shortName: 'ProjectT2BlueprintsToProvide',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/t2-blueprints-to-provide',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectT2BlueprintsToProvideProvider::class,
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectT2BlueprintsToProvideResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public bool $excludesInvention = false;

    /**
     * @var list<array{productTypeId: int, typeName: string, runs: int}>
     */
    public array $lines = [];
}
