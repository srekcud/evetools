<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\PurchaseSuggestionProvider;

#[ApiResource(
    shortName: 'PurchaseSuggestionList',
    description: 'Purchase suggestions from wallet transactions matching project materials',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{id}/purchase-suggestions',
            provider: PurchaseSuggestionProvider::class,
            openapi: new Model\Operation(summary: 'Get purchase suggestions', description: 'Returns wallet transactions matching project materials', tags: ['Industry - Projects']),
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
