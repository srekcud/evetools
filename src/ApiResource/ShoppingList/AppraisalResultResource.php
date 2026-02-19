<?php

declare(strict_types=1);

namespace App\ApiResource\ShoppingList;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\ShoppingList\AppraiseInput;
use App\State\Processor\ShoppingList\AppraiseProcessor;

#[ApiResource(
    shortName: 'AppraisalResult',
    operations: [
        new Post(
            uriTemplate: '/shopping-list/appraise',
            processor: AppraiseProcessor::class,
            input: AppraiseInput::class,
            openapi: new Model\Operation(tags: ['Shopping List']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AppraisalResultResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'appraise';

    /** @var list<AppraisalItemResource> */
    public array $items = [];

    /** @var list<string> */
    public array $notFound = [];

    /** @var array<string, float|null> */
    public array $totals = [];

    public ?string $priceError = null;
}
