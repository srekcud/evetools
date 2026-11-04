<?php

declare(strict_types=1);

namespace App\ApiResource\MoonTax;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\MoonTax\MoonTaxCorporationProvider;

#[ApiResource(
    shortName: 'MoonTaxCorporation',
    description: 'Moon tax refinery settings and sync access of a corporation',
    operations: [
        new Get(
            uriTemplate: '/mining/moon-tax/{corporationId}',
            uriVariables: [
                'corporationId' => new Link(fromClass: self::class),
            ],
            requirements: ['corporationId' => '\d+'],
            provider: MoonTaxCorporationProvider::class,
            openapi: new Model\Operation(summary: 'Get the moon tax settings and the sync access of a corporation', tags: ['Moon Tax']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    paginationEnabled: false,
)]
class MoonTaxCorporationResource
{
    #[ApiProperty(identifier: true)]
    public int $corporationId;

    /** @var list<array{structureId: int, openToAlliance: bool, period: string, rateR4: float, rateR8: float, rateR16: float, rateR32: float, rateR64: float, walletDivision: int}> */
    public array $refineries = [];

    /** @var array{ledger: string, payments: string} */
    public array $access = ['ledger' => 'never_synced', 'payments' => 'never_synced'];

    public ?\DateTimeImmutable $observersSyncedAt = null;

    public ?\DateTimeImmutable $walletSyncedAt = null;

    public static function identifiedBy(int $corporationId): self
    {
        $resource = new self();
        $resource->corporationId = $corporationId;

        return $resource;
    }
}
