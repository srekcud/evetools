<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectSaleSuggestionProvider;

#[ApiResource(
    shortName: 'ProjectSaleSuggestion',
    description: 'Wallet transactions that can be recorded as sales of a group industry project',
    operations: [
        new GetCollection(
            uriTemplate: '/industry/projects/{projectId}/sale-suggestions',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectSaleSuggestionProvider::class,
            openapi: new Model\Operation(
                summary: 'List sale suggestions',
                description: 'Returns the unlinked sales of the wallet of the current user that match an item of the project (admin/owner only)',
                tags: ['Group Industry - Sales'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class ProjectSaleSuggestionResource
{
    #[ApiProperty(identifier: true)]
    public string $transactionId;

    public int $typeId;

    public string $typeName;

    public int $quantity;

    public float $unitPrice;

    public float $totalPrice;

    public string $date;

    public int $sellerCharacterId;

    public string $sellerCharacterName;

    public int $locationId;

    /** @var string[] */
    public array $possibleDuplicateSaleIds = [];
}
