<?php

declare(strict_types=1);

namespace App\ApiResource\Contract;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Contract\ContractCollectionProvider;
use App\State\Provider\Contract\ContractItemsProvider;

#[ApiResource(
    shortName: 'Contract',
    description: 'User contracts with price comparison',
    operations: [
        new Get(
            uriTemplate: '/contracts',
            provider: ContractCollectionProvider::class,
            output: ContractListResource::class,
            openapi: new Model\Operation(
                summary: 'List user contracts with price comparison',
                tags: ['Inventory'],
                parameters: [
                    new Model\Parameter(name: 'status', in: 'query', schema: ['type' => 'string']),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/contracts/{contractId}/items',
            provider: ContractItemsProvider::class,
            output: ContractItemsResource::class,
            openapi: new Model\Operation(summary: 'Get contract items with Jita prices', tags: ['Inventory']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ContractResource
{
    #[ApiProperty(identifier: true)]
    public int $contractId = 0;

    public string $type = '';

    public string $status = '';

    public string $title = '';

    public float $price = 0.0;

    public float $reward = 0.0;

    public float $volume = 0.0;

    public string $dateIssued = '';

    public ?string $dateExpired = null;

    public ?string $dateCompleted = null;

    public int $issuerId = 0;

    public ?int $assigneeId = null;

    public int $acceptorId = 0;

    public bool $forCorporation = false;

    public bool $isSeller = false;

    /** @var ContractItemResource[] */
    public array $items = [];

    public int $itemCount = 0;

    public ?float $jitaValue = null;

    public ?float $jitaDiff = null;

    public ?float $jitaDiffPercent = null;

    public ?float $delveValue = null;

    public ?float $delveDiff = null;

    public ?float $delveDiffPercent = null;

    public int $similarCount = 0;

    public ?float $lowestSimilar = null;

    public ?float $avgSimilar = null;

    public ?float $similarDiff = null;

    public ?float $similarDiffPercent = null;

    public ?bool $isCompetitive = null;
}
