<?php

declare(strict_types=1);

namespace App\ApiResource\MoonTax;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MoonTax\MoonTaxDonationCollectionProvider;

#[ApiResource(
    shortName: 'MoonTaxDonationEntry',
    description: 'Wallet donations received by a corporation over the last 90 days, with the miner each is attributed to',
    operations: [
        new GetCollection(
            uriTemplate: '/mining/moon-tax/{corporationId}/donations',
            uriVariables: [
                'corporationId' => new Link(fromClass: MoonTaxCorporationResource::class),
            ],
            provider: MoonTaxDonationCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List the recent donations of a corporation', tags: ['Moon Tax']),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MoonTaxDonationEntryResource
{
    #[ApiProperty(identifier: true)]
    public int $journalId;

    #[ApiProperty(readable: false, writable: false)]
    public ?MoonTaxCorporationResource $corporation = null;

    public int $corporationId;

    public \DateTimeImmutable $date;

    public float $amount;

    public ?string $reason = null;

    public int $firstPartyId;

    public ?string $firstPartyName = null;

    public ?int $attributedCharacterId = null;

    public ?string $attributedCharacterName = null;

    public ?\DateTimeImmutable $attributedAt = null;

    public bool $payerIsMiner = false;
}
