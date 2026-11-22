<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Industry\Project\RefreshStructureAccessProcessor;

#[ApiResource(
    shortName: 'ProjectStructureAccessRefresh',
    description: 'A queued refresh of the structure access of the members of a project',
    operations: [
        new Post(
            uriTemplate: '/industry/projects/{projectId}/structure-access/refresh',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            status: 202,
            processor: RefreshStructureAccessProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            openapi: new Model\Operation(
                summary: 'Refresh the structure access of the project members',
                description: 'Project owner or admin only. Queues the read of the structures of the plan for the characters of the accepted members; the end is published on the project Mercure topic as structure_access_changed',
                tags: ['Group Industry - Stations'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectStructureAccessRefreshResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId = '';

    public string $status = 'queued';
}
