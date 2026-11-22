<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Industry\Project\StoreSurplusProcessor;
use App\State\Provider\Industry\Project\ProjectSurplusProvider;

#[ApiResource(
    shortName: 'ProjectSurplus',
    description: 'Surplus of a completed industry project: approved material contributions above the required quantity, valued at the contribution price, and the intermediate surplus of its job lines (whole runs), not valued',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/surplus',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectSurplusProvider::class,
            openapi: new Model\Operation(
                summary: 'Read the material surplus of the project',
                description: 'Lists the BOM lines whose approved material contributions exceed the required quantity, with the surplus valued at the contribution price, and the intermediates produced beyond the demand (source production, not valued, left out of the total); read only, nothing is written to the Stockpile and the profit is unchanged',
                tags: ['Group Industry - Materials'],
            ),
        ),
        new Post(
            uriTemplate: '/industry/projects/{projectId}/surplus-storage',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            status: 200,
            processor: StoreSurplusProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            openapi: new Model\Operation(
                summary: 'Declare the surplus stored in the corporation stock',
                description: 'Declares that the surplus was put away (project owner or admin, selling or completed project with a surplus, once); nothing is moved',
                tags: ['Group Industry - Materials'],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class ProjectSurplusResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public ?float $totalValue = null;

    public bool $stored = false;

    public ?string $storedAt = null;

    public ?string $storedBy = null;

    /**
     * @var list<array{lineId: string, typeId: int, typeName: string, requiredQuantity: int, contributedQuantity: int, surplusQuantity: int, unitPrice: float|null, surplusValue: float|null, source: string}>
     */
    public array $lines = [];
}
