<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectStationAccessProvider;

#[ApiResource(
    shortName: 'ProjectStationAccess',
    description: 'How many accepted members can launch a job in each station of the project plan',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/station-access',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectStationAccessProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(
                summary: 'Count the members who can launch, are denied or unknown, station by station',
                description: 'Project owner or admin only. One entry per station of the plan; counts only, never the characters of a member',
                tags: ['Group Industry - Stations'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectStationAccessResource
{
    #[ApiProperty(identifier: true)]
    public int $locationId = 0;

    public ?string $name = null;

    public ?int $solarSystemId = null;

    public int $canLaunch = 0;

    public int $denied = 0;

    public int $unknown = 0;
}
