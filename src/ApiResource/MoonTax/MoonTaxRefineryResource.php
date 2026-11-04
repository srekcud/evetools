<?php

declare(strict_types=1);

namespace App\ApiResource\MoonTax;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\MoonTax\MoonTaxRefineryInput;
use App\State\Processor\MoonTax\SetMoonTaxRefineryProcessor;

#[ApiResource(
    shortName: 'MoonTaxRefinery',
    description: 'Moon tax setting of a refinery known from the moon extractions',
    operations: [
        new Put(
            uriTemplate: '/mining/moon-tax/{corporationId}/refineries/{structureId}',
            uriVariables: [
                'corporationId' => new Link(fromClass: MoonTaxCorporationResource::class),
                'structureId' => new Link(fromClass: self::class),
            ],
            read: false,
            processor: SetMoonTaxRefineryProcessor::class,
            input: MoonTaxRefineryInput::class,
            openapi: new Model\Operation(summary: 'Set the moon tax of a refinery', tags: ['Moon Tax']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MoonTaxRefineryResource
{
    #[ApiProperty(identifier: true)]
    public int $structureId;

    #[ApiProperty(readable: false, writable: false)]
    public ?MoonTaxCorporationResource $corporation = null;

    public int $corporationId;

    public bool $openToAlliance;

    public string $period;

    public float $rateR4;

    public float $rateR8;

    public float $rateR16;

    public float $rateR32;

    public float $rateR64;

    public int $walletDivision;
}
