<?php

declare(strict_types=1);

namespace App\ApiResource\MiningSite;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MiningSite\RecordMiningSiteScanInput;
use App\State\Processor\MiningSite\HideMiningSiteScanProcessor;
use App\State\Processor\MiningSite\RecordMiningSiteScanProcessor;
use App\State\Provider\MiningSite\MiningSiteScanCollectionProvider;
use App\State\Provider\MiningSite\MiningSiteScanItemProvider;

#[ApiResource(
    shortName: 'MiningSiteScan',
    description: 'Scans of a mining site with the depletion metrics announced at each scan',
    operations: [
        new Post(
            uriTemplate: '/mining-sites/{siteId}/scans',
            uriVariables: [
                'siteId' => new Link(fromClass: MiningSiteResource::class),
            ],
            processor: RecordMiningSiteScanProcessor::class,
            input: RecordMiningSiteScanInput::class,
            openapi: new Model\Operation(summary: 'Record a scan of a mining site', tags: ['Mining Sites']),
        ),
        new GetCollection(
            uriTemplate: '/mining-sites/{siteId}/scans',
            uriVariables: [
                'siteId' => new Link(fromClass: MiningSiteResource::class),
            ],
            provider: MiningSiteScanCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List the scans of a mining site', tags: ['Mining Sites']),
        ),
        new Get(
            uriTemplate: '/mining-sites/{siteId}/scans/{id}',
            uriVariables: [
                'siteId' => new Link(fromClass: MiningSiteResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: MiningSiteScanItemProvider::class,
            openapi: new Model\Operation(summary: 'Get a scan of a mining site', tags: ['Mining Sites']),
        ),
        new Delete(
            uriTemplate: '/mining-sites/{siteId}/scans/{id}',
            uriVariables: [
                'siteId' => new Link(fromClass: MiningSiteResource::class),
                'id' => new Link(fromClass: self::class),
            ],
            provider: MiningSiteScanItemProvider::class,
            processor: HideMiningSiteScanProcessor::class,
            openapi: new Model\Operation(summary: 'Hide a scan of a mining site from the estimates to come', tags: ['Mining Sites']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningSiteScanResource
{
    #[ApiProperty(identifier: true)]
    public ?string $id = null;

    #[ApiProperty(readable: false, writable: false)]
    public ?MiningSiteResource $site = null;

    public ?int $number = null;

    public ?string $authorName = null;

    public ?int $typeCount = null;

    public ?\DateTimeImmutable $scannedAt = null;

    public ?int $totalUnits = null;

    public ?float $totalVolumeM3 = null;

    public ?float $remainingValue = null;

    /** @var list<int> */
    public array $missingPriceTypeIds = [];

    public ?\DateTimeImmutable $recentEta = null;

    public ?\DateTimeImmutable $trendEta = null;

    /** @var list<array<string, mixed>> */
    public array $types = [];

    /** @var array{market: string, side: string, form: string, structureName: string|null}|null */
    public ?array $valuationSource = null;
}
