<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Industry\Project\IndustryProjectResource;
use App\State\Provider\Industry\PurchaseSuggestionProvider;

#[ApiResource(
    shortName: 'PurchaseSuggestionList',
    description: 'Purchase suggestions from wallet transactions matching the products of the project BOM lines',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/purchase-suggestions',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: PurchaseSuggestionProvider::class,
            openapi: new Model\Operation(summary: 'Get purchase suggestions', description: 'Returns wallet transactions matching the products of the BOM lines', tags: ['Industry - Projects']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class PurchaseSuggestionListResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    /** @var PurchaseSuggestionResource[] */
    public array $suggestions = [];

    public int $totalCount = 0;
}
