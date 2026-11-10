<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\Project\ProjectDistributionProvider;

#[ApiResource(
    shortName: 'ProjectDistribution',
    description: 'Profit distribution calculation for a group industry project',
    operations: [
        new Get(
            uriTemplate: '/industry/projects/{projectId}/distribution',
            uriVariables: [
                'projectId' => new Link(fromClass: IndustryProjectResource::class),
            ],
            provider: ProjectDistributionProvider::class,
            openapi: new Model\Operation(
                summary: 'Get distribution',
                description: 'Returns the full profit distribution calculation for the project',
                tags: ['Group Industry - Distribution'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProjectDistributionResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public float $totalRevenue;

    public float $marketRevenue = 0.0;

    public float $contractRevenue = 0.0;

    public ?float $contractFees = 0.0;

    public ?float $brokerFee;

    public ?float $salesTax;

    public ?float $netRevenue;

    public ?float $totalProjectCost;

    public ?float $marginPercent;

    public bool $awaitingSales = false;

    /**
     * @var list<array{typeId: int, typeName: string, unsoldQuantity: int, lastUnitPrice: float|null}>
     */
    public array $unsoldProducts = [];

    public ?float $projectedMarginPercent = null;

    public ?float $breakEvenPerUnit = null;

    public ?string $breakEvenUnknownReason = null;

    /**
     * @var list<array{
     *     memberId: string,
     *     characterName: string|null,
     *     totalCostsEngaged: float|null,
     *     materialCosts: float|null,
     *     jobInstallCosts: float|null,
     *     bpcCosts: float|null,
     *     lineRentalCosts: float|null,
     *     courierCosts: float|null,
     *     sharePercent: float|null,
     *     profitPart: float|null,
     *     payoutTotal: float|null,
     * }>
     */
    public array $members = [];
}
