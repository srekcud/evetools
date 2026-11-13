<?php

declare(strict_types=1);

namespace App\ApiResource\MoonTax;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\MoonTax\CancelMoonTaxStatementProcessor;
use App\State\Processor\MoonTax\MarkMoonTaxStatementPaidProcessor;
use App\State\Provider\MoonTax\MoonTaxStatementCollectionProvider;

#[ApiResource(
    shortName: 'MoonTaxStatement',
    description: 'Moon tax statements of a corporation, one per character, refinery and period',
    operations: [
        new GetCollection(
            uriTemplate: '/mining/moon-tax/{corporationId}/statements',
            uriVariables: [
                'corporationId' => new Link(fromClass: MoonTaxCorporationResource::class),
            ],
            formats: ['json' => ['application/json'], 'csv' => ['text/csv']],
            provider: MoonTaxStatementCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List the moon tax statements of a corporation', tags: ['Moon Tax']),
        ),
        new Post(
            uriTemplate: '/mining/moon-tax/{corporationId}/statements/{id}/mark-paid',
            uriVariables: [
                'corporationId' => new Link(fromClass: MoonTaxCorporationResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            processor: MarkMoonTaxStatementPaidProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            status: 200,
            openapi: new Model\Operation(summary: 'Mark a moon tax statement as paid', tags: ['Moon Tax']),
        ),
        new Post(
            uriTemplate: '/mining/moon-tax/{corporationId}/statements/{id}/cancel',
            uriVariables: [
                'corporationId' => new Link(fromClass: MoonTaxCorporationResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            processor: CancelMoonTaxStatementProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            status: 200,
            openapi: new Model\Operation(summary: 'Cancel a moon tax statement', tags: ['Moon Tax']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MoonTaxStatementResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    #[ApiProperty(readable: false, writable: false)]
    public ?MoonTaxCorporationResource $corporation = null;

    public int $corporationId;

    public int $structureId;

    public int $characterId;

    public ?string $characterName = null;

    public \DateTimeImmutable $periodStart;

    public \DateTimeImmutable $periodEnd;

    public ?string $reference = null;

    public ?float $tax = null;

    public float $paid = 0.0;

    public ?float $remaining = null;

    public string $status;

    public ?bool $outsideAlliance = null;

    public ?int $recordedCorporationId = null;

    public ?string $recordedCorporationName = null;

    public ?int $allianceId = null;

    public ?string $allianceName = null;

    public bool $affiliationKnown = false;

    /** @var list<int> */
    public array $unknownTypeIds = [];
}
