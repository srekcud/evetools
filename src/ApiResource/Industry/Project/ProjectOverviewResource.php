<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectOverviewCollectionProvider;

#[ApiResource(
    shortName: 'IndustryProjectOverview',
    description: 'Role, team, flow progress and awaited participants of each project of the connected pilot',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/overview',
            provider: ProjectOverviewCollectionProvider::class,
            openapi: new Model\Operation(
                summary: 'List my projects with their flow progress',
                description: 'One entry per project of the pilot: role, owner and team, number of cards in each flow column, and who is awaited',
                tags: ['Group Industry - Projects'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
    paginationEnabled: false,
)]
class ProjectOverviewResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId = '';

    public string $myRole = '';

    public string $ownerName = '';

    /**
     * @var list<array{name: string, role: string}>
     */
    public array $team = [];

    /**
     * @var array{toBring: int, onSite: int, inProduction: int, out: int, done: int}|null
     */
    public ?array $flow = null;

    public ?string $flowReason = null;

    public int $flowUnknown = 0;

    /**
     * @var list<array{name: string, mine: bool, count: int}>
     */
    public array $awaited = [];
}
