<?php

declare(strict_types=1);

namespace App\ApiResource\Market;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Market\CreateAlertInput;
use App\State\Processor\Market\CreateAlertProcessor;
use App\State\Processor\Market\DeleteAlertProcessor;
use App\State\Provider\Market\MarketAlertCollectionProvider;
use App\State\Provider\Market\MarketAlertDeleteProvider;

#[ApiResource(
    shortName: 'MarketAlert',
    description: 'Market price alerts',
    operations: [
        new GetCollection(
            uriTemplate: '/market/alerts',
            provider: MarketAlertCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List alerts with current prices'),
        ),
        new Post(
            uriTemplate: '/market/alerts',
            input: CreateAlertInput::class,
            processor: CreateAlertProcessor::class,
        ),
        new Delete(
            uriTemplate: '/market/alerts/{id}',
            provider: MarketAlertDeleteProvider::class,
            processor: DeleteAlertProcessor::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MarketAlertResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $typeId;

    public string $typeName;

    public string $direction;

    public float $threshold;

    public string $priceSource;

    public string $status;

    public ?float $currentPrice = null;

    public ?string $triggeredAt = null;

    public string $createdAt;
}
