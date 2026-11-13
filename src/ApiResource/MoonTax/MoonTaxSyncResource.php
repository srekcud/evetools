<?php

declare(strict_types=1);

namespace App\ApiResource\MoonTax;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\MoonTax\TriggerMoonTaxCorporationSyncProcessor;

#[ApiResource(
    shortName: 'MoonTaxSync',
    description: 'On-demand moon tax sync of a corporation',
    operations: [
        new Post(
            uriTemplate: '/mining/moon-tax/{corporationId}/sync',
            uriVariables: [
                'corporationId' => new Link(fromClass: MoonTaxCorporationResource::class),
            ],
            processor: TriggerMoonTaxCorporationSyncProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            status: 200,
            openapi: new Model\Operation(summary: 'Trigger the moon tax sync of a corporation', tags: ['Moon Tax']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MoonTaxSyncResource
{
    #[ApiProperty(readable: false, writable: false)]
    public ?MoonTaxCorporationResource $corporation = null;

    #[ApiProperty(identifier: true)]
    public int $corporationId;

    public string $status = 'queued';
}
