<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\IndustryActionsProvider;

#[ApiResource(
    shortName: 'IndustryAction',
    description: 'Next action of each industry project of the connected pilot',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/actions',
            provider: IndustryActionsProvider::class,
            openapi: new Model\Operation(
                summary: 'List the action queue',
                description: 'Returns the next action of every project of the connected pilot, sorted by impact',
                tags: ['Group Industry - Actions'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
    paginationEnabled: false,
)]
class IndustryActionResource
{
    #[ApiProperty(identifier: true)]
    public string $projectId;

    public ?string $projectName = null;

    public string $code;

    public bool $blocked;

    public ?string $tab = null;

    public ?string $targetStatus = null;

    public ?string $bomLineId = null;

    public ?int $typeId = null;

    public ?string $typeName = null;

    public ?int $quantity = null;

    public string $severity;

    public ?float $impactIsk = null;

    public ?float $jitaUnitPrice = null;

    public ?float $breakEvenPerUnit = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $undercutOrder = null;
}
