<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\ApiResource\Input\Mining\MiningValuationProfileInput;
use App\State\Processor\Mining\UpdateMiningValuationProfileProcessor;
use App\State\Provider\Mining\MiningValuationProfileProvider;

#[ApiResource(
    shortName: 'MiningValuationProfile',
    operations: [
        new Get(
            uriTemplate: '/mining/valuation-profile',
            provider: MiningValuationProfileProvider::class,
        ),
        new Patch(
            uriTemplate: '/mining/valuation-profile',
            provider: MiningValuationProfileProvider::class,
            processor: UpdateMiningValuationProfileProcessor::class,
            input: MiningValuationProfileInput::class,
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
)]
class MiningValuationProfileResource
{
    public string $market = '';

    public string $side = '';

    public string $form = '';

    public ?int $structureId = null;

    public ?string $structureName = null;

    public float $exportTax = 0.0;

    public bool $persisted = false;
}
