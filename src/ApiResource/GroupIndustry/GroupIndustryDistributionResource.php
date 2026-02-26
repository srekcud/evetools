<?php

declare(strict_types=1);

namespace App\ApiResource\GroupIndustry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\GroupIndustry\GroupDistributionProvider;

#[ApiResource(
    shortName: 'GroupIndustryDistribution',
    description: 'Profit distribution calculation for a group industry project',
    operations: [
        new Get(
            uriTemplate: '/group-industry/projects/{projectId}/distribution',
            provider: GroupDistributionProvider::class,
            openapi: new Model\Operation(
                summary: 'Get distribution',
                description: 'Returns the full profit distribution calculation for the project',
                tags: ['Group Industry - Distribution'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class GroupIndustryDistributionResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public float $totalRevenue;

    public float $brokerFee;

    public float $salesTax;

    public float $netRevenue;

    public float $totalProjectCost;

    public float $marginPercent;

    /**
     * @var list<array{
     *     memberId: string,
     *     characterName: string,
     *     totalCostsEngaged: float,
     *     materialCosts: float,
     *     jobInstallCosts: float,
     *     bpcCosts: float,
     *     lineRentalCosts: float,
     *     sharePercent: float,
     *     profitPart: float,
     *     payoutTotal: float,
     * }>
     */
    public array $members = [];
}
