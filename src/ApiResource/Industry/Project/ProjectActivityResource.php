<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectActivityProvider;

#[ApiResource(
    shortName: 'ProjectActivity',
    description: 'Activity feed of an industry project: the latest recorded events and the ESI jobs running now',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/activity',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectActivityProvider::class,
            openapi: new Model\Operation(
                summary: 'Read the activity feed of the project',
                description: 'Lists the latest recorded events of the project from the most recent to the oldest, and the active ESI jobs linked to the project grouped by pilot and BOM line; read only',
                tags: ['Group Industry - Activity'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectActivityResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /**
     * @var list<array{id: string, kind: string, occurredAt: string, data: array<string, mixed>}>
     */
    public array $events = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $runningJobs = [];
}
