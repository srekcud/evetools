<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Mining\SyncCorporationMiningExtractionsProcessor;
use App\State\Provider\Mining\CorporationMiningExtractionsProvider;

#[ApiResource(
    shortName: 'CorporationMiningExtractions',
    operations: [
        new Get(
            uriTemplate: '/mining/corporation-extractions',
            provider: CorporationMiningExtractionsProvider::class,
        ),
        new Post(
            uriTemplate: '/mining/corporation-extractions/sync',
            processor: SyncCorporationMiningExtractionsProcessor::class,
            input: EmptyInput::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CorporationMiningExtractionsResource
{
    /**
     * @var list<array{
     *     corporationId: int,
     *     access: string,
     *     lastSuccessAt: ?string,
     *     extractions: list<array<string, int|string|null>>
     * }>
     */
    public array $corporations = [];
}
