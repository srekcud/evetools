<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectReferencesProvider;

#[ApiResource(
    shortName: 'ProjectReferences',
    description: 'Real unit cost and margin of the last single-product projects finished less than a month ago, one per product',
    operations: [
        new Get(
            uriTemplate: '/industry/project-references',
            provider: ProjectReferencesProvider::class,
            openapi: new Model\Operation(
                summary: 'Read the project references of the caller',
                description: 'Last completed single-product project of each product, finished less than a month ago, where the caller is the owner or an accepted participant; unknown figures are null',
                tags: ['Group Industry - Projects'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectReferencesResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'project-references';

    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];
}
