<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectPlanningProvider;

#[ApiResource(
    shortName: 'ProjectPlanning',
    description: 'Earliest planning of the jobs of an industry project against the linked ESI jobs, one lane per character',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/planning',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectPlanningProvider::class,
            openapi: new Model\Operation(
                summary: 'Get project planning',
                description: 'Returns the planned and real jobs per character, the expected end, the delay and its cause',
                tags: ['Group Industry - Planning'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectPlanningResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $anchor;

    public bool $structureTimeBonusIncluded;

    public ?string $plannedEnd = null;

    public ?string $expectedEnd = null;

    public ?int $delaySeconds = null;

    /**
     * @var array{kind: string, names: list<string>, lateSeconds: int}|null
     */
    public ?array $delayCause = null;

    public bool $lanesShown = false;

    /**
     * @var list<array<string, mixed>>
     */
    public array $characters = [];

    /**
     * @var list<array{lineId: string, typeName: string, blueprintName: string|null, reason: string}>
     */
    public array $unscheduled = [];
}
