<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectCockpitProvider;

#[ApiResource(
    shortName: 'ProjectCockpit',
    description: 'Phases and next action of an industry project',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/cockpit',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectCockpitProvider::class,
            openapi: new Model\Operation(
                summary: 'Get project cockpit',
                description: 'Returns the five phases of the project and the next action of the connected pilot',
                tags: ['Group Industry - Cockpit'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectCockpitResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId;

    public string $status;

    /**
     * @var list<array{
     *     key: string,
     *     state: string,
     *     counter: array{done: int, total: int},
     *     blockedReason: array{code: string, count: int}|null,
     * }>
     */
    public array $phases;

    /**
     * @var array{
     *     code: string,
     *     blocked: bool,
     *     tab: string|null,
     *     targetStatus: string|null,
     *     bomLineId: string|null,
     *     typeId: int|null,
     *     typeName: string|null,
     *     quantity: int|null,
     * }
     */
    public array $nextAction;

    /**
     * @var array{
     *     typeId: int,
     *     typeName: string,
     *     quantity: int,
     *     jitaUnitPrice: float,
     *     breakEvenPerUnit: float,
     *     gapPerUnit: float,
     * }|null
     */
    public ?array $priceAlert = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $undercutOrders = [];
}
