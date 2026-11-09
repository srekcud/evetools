<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectResearchProvider;

#[ApiResource(
    shortName: 'ProjectResearch',
    description: 'ME research of the owned blueprints of a project, compared with its gain on the planned runs',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/research',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectResearchProvider::class,
            openapi: new Model\Operation(
                summary: 'Compare the ME research of each owned original with its gain on the project runs',
                description: 'Returns one row per owned original blueprint used by the plan, with the material saving of its next ME level, the research install cost and the verdict',
                tags: ['Group Industry - Research'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectResearchResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /**
     * @var list<array<string, mixed>>
     */
    public array $rows = [];
}
