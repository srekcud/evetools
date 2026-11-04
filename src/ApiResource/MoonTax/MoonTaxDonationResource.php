<?php

declare(strict_types=1);

namespace App\ApiResource\MoonTax;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MoonTax\AttributeMoonTaxDonationInput;
use App\State\Processor\MoonTax\AttributeMoonTaxDonationProcessor;

#[ApiResource(
    shortName: 'MoonTaxDonation',
    description: 'Wallet donations received by a corporation and the miner they are attributed to',
    operations: [
        new Post(
            uriTemplate: '/mining/moon-tax/{corporationId}/donations/{journalId}/attribute',
            uriVariables: [
                'corporationId' => new Link(fromClass: MoonTaxCorporationResource::class),
                'journalId' => new Link(fromClass: self::class),
            ],
            processor: AttributeMoonTaxDonationProcessor::class,
            input: AttributeMoonTaxDonationInput::class,
            status: 200,
            openapi: new Model\Operation(summary: 'Attribute a donation to a miner', tags: ['Moon Tax']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MoonTaxDonationResource
{
    #[ApiProperty(identifier: true)]
    public int $journalId;

    #[ApiProperty(readable: false, writable: false)]
    public ?MoonTaxCorporationResource $corporation = null;

    public int $corporationId;

    public int $firstPartyId;

    public ?int $attributedCharacterId = null;

    public ?\DateTimeImmutable $attributedAt = null;
}
