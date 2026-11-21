<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectJobsSyncProvider;

#[ApiResource(
    shortName: 'ProjectJobsSync',
    description: 'When the industry jobs of each accepted participant of a project were last synced',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/jobs-sync',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectJobsSyncProvider::class,
            openapi: new Model\Operation(
                summary: 'Read the last jobs sync of each participant',
                description: 'Lists the owner then every accepted participant with the oldest of the newest job cache dates of their characters, and the interval of the scheduled jobs sync; read only',
                tags: ['Group Industry - Jobs'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectJobsSyncResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $intervalSeconds;

    /**
     * @var list<array{name: string, isViewer: bool, lastSyncedAt: string|null}>
     */
    public array $installers = [];
}
