<?php

declare(strict_types=1);

namespace App\ApiResource\MiningSite;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\MiningSite\CreateMiningSiteInput;
use App\ApiResource\Input\MiningSite\NextMiningSiteInput;
use App\ApiResource\MiningOperation\MiningOperationResource;
use App\State\Processor\MiningSite\CloseMiningSiteProcessor;
use App\State\Processor\MiningSite\CreateMiningSiteProcessor;
use App\State\Processor\MiningSite\NextMiningSiteProcessor;
use App\State\Provider\MiningSite\MiningSiteCollectionProvider;
use App\State\Provider\MiningSite\MiningSiteItemProvider;

#[ApiResource(
    shortName: 'MiningSite',
    description: 'Belts and anomalies followed by scans, with their depletion history',
    operations: [
        new Post(
            uriTemplate: '/mining-sites',
            processor: CreateMiningSiteProcessor::class,
            input: CreateMiningSiteInput::class,
            openapi: new Model\Operation(summary: 'Create a mining site', tags: ['Mining Sites']),
        ),
        new GetCollection(
            uriTemplate: '/mining-sites',
            provider: MiningSiteCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List my mining sites', tags: ['Mining Sites']),
        ),
        new Get(
            uriTemplate: '/mining-sites/{id}',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            provider: MiningSiteItemProvider::class,
            openapi: new Model\Operation(summary: 'Get a mining site', tags: ['Mining Sites']),
        ),
        new Post(
            uriTemplate: '/mining-sites/{id}/close',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            processor: CloseMiningSiteProcessor::class,
            input: EmptyInput::class,
            deserialize: false,
            status: 200,
            openapi: new Model\Operation(summary: 'Close a mining site', tags: ['Mining Sites']),
        ),
        new Post(
            uriTemplate: '/mining-operations/{operationId}/next-site',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            processor: NextMiningSiteProcessor::class,
            input: NextMiningSiteInput::class,
            openapi: new Model\Operation(summary: 'Close the active sites of a mining operation and open the next one', tags: ['Mining Sites']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningSiteResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    public ?string $operationId = null;

    public ?string $label = null;

    public ?int $solarSystemId = null;

    public ?string $solarSystemName = null;

    public ?string $status = null;

    public ?\DateTimeImmutable $createdAt = null;

    public ?\DateTimeImmutable $firstScanAt = null;

    public ?\DateTimeImmutable $lastScanAt = null;

    public ?int $observedSeconds = null;

    public ?float $firstVolumeM3 = null;

    public ?float $firstValue = null;

    public ?float $averageTrendRate = null;

    public ?float $remainingValue = null;

    /** @var list<int> */
    public array $missingPriceTypeIds = [];

    public ?\DateTimeImmutable $recentEta = null;

    public ?\DateTimeImmutable $trendEta = null;

    public ?\DateTimeImmutable $closedAt = null;

    public int $scanCount = 0;

    public ?float $extractedValue = null;

    public ?int $lastScanNumber = null;

    public ?string $lastScanAuthorName = null;

    /** @var array<string, mixed>|null */
    public ?array $compression = null;

    public ?\DateTimeImmutable $ledgerEta = null;

    public ?string $ledgerEtaUnknownReason = null;
}
