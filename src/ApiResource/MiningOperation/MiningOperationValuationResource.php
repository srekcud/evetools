<?php

declare(strict_types=1);

namespace App\ApiResource\MiningOperation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MiningOperation\UpdateMiningOperationValuationInput;
use App\State\Processor\MiningOperation\UpdateMiningOperationValuationProcessor;
use App\State\Provider\MiningOperation\MiningOperationValuationProvider;

#[ApiResource(
    shortName: 'MiningOperationValuation',
    description: 'Valuation settings, mined values and shares of a mining operation',
    operations: [
        new Get(
            uriTemplate: '/mining-operations/{operationId}/valuation',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            provider: MiningOperationValuationProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the valuation of a mining operation',
                description: 'Returns the valuation settings, the unit prices, the mined value and the share of each participant (participants only)',
                tags: ['Mining Operations - Valuation'],
            ),
        ),
        new Patch(
            uriTemplate: '/mining-operations/{operationId}/valuation',
            uriVariables: [
                'operationId' => new Link(fromClass: MiningOperationResource::class),
            ],
            inputFormats: ['json' => ['application/merge-patch+json']],
            provider: MiningOperationValuationProvider::class,
            processor: UpdateMiningOperationValuationProcessor::class,
            input: UpdateMiningOperationValuationInput::class,
            openapi: new Model\Operation(
                summary: 'Update the valuation settings of a mining operation',
                description: 'Changes the valuation basis, the booster cut, the booster and the corporation cut until the shares are frozen (owner or admin)',
                tags: ['Mining Operations - Valuation'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MiningOperationValuationResource
{
    public ?string $operationId = null;

    public ?string $status = null;

    public ?string $splitMode = null;

    public bool $frozen = false;

    public ?\DateTimeImmutable $sharesFrozenAt = null;

    public ?\DateTimeImmutable $pricesReadAt = null;

    public ?\DateTimeImmutable $pricesSyncedAt = null;

    /** @var array<string, mixed> */
    public array $settings = [];

    /** @var list<array{typeId: int, typeName: ?string, unitPrice: ?float}> */
    public array $unitPrices = [];

    /** @var list<int> */
    public array $missingPriceTypeIds = [];

    public ?int $totalValue = null;

    public ?int $boosterCut = null;

    public ?int $corporationCut = null;

    public ?float $iskPerHour = null;

    public ?int $durationSeconds = null;

    public ?int $roundingRemainder = null;

    public ?int $boosterRoundingAdjustment = null;

    public ?int $corporationRoundingAdjustment = null;

    /** @var list<array<string, mixed>> */
    public array $participants = [];

    public bool $canFreeze = false;

    /** @var list<string> */
    public array $freezeBlockers = [];
}
