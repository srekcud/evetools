<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\IndustryWorkshopProjectsProvider;

#[ApiResource(
    shortName: 'IndustryWorkshopProject',
    description: 'Team, lifecycle phases, profit and engaged capital of each industry project of the connected pilot',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/workshop-projects',
            provider: IndustryWorkshopProjectsProvider::class,
            openapi: new Model\Operation(
                summary: 'List the workshop project rows',
                description: 'Returns the accepted participants, the cockpit phases, the profit share and the capital engaged by the connected pilot for every project of the pilot',
                tags: ['Group Industry - Actions'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
    paginationEnabled: false,
)]
class IndustryWorkshopProjectResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId;

    /** @var list<array{characterId: int|null, characterName: string, role: string}> */
    public array $members = [];

    public ?string $corporationName = null;

    /**
     * @var list<array{
     *     key: string,
     *     state: string,
     *     counter: array{done: int, total: int},
     *     blockedReason: array{code: string, count: int}|null,
     * }>
     */
    public array $phases = [];

    /** @var array{amount: float|null, status: string} */
    public array $profit = ['amount' => null, 'status' => 'unknown'];

    public ?float $engagedCapital = null;
}
