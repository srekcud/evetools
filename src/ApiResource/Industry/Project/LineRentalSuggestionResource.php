<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\LineRentalSuggestionProvider;

#[ApiResource(
    shortName: 'LineRentalSuggestion',
    description: 'Suggested value of the line rental of a bill of materials line, computed from the rate schedules',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/line-rental-suggestions/{id}',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: LineRentalSuggestionProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the line rental suggestion of a line',
                description: 'Returns the line rental value suggested for the current user on a bill of materials line, or the reason it is unknown (accepted member or owner only)',
                tags: ['Group Industry - Contributions'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class LineRentalSuggestionResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $bomItemId;

    public ?float $suggestedValue = null;

    public ?string $unknownReason = null;

    public ?string $category = null;

    public ?float $days = null;

    public ?int $runs = null;

    public ?float $dayFactor = null;

    public ?float $ratePerRun = null;
}
