<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Admin\ChartDataProvider;

#[ApiResource(
    shortName: 'AdminChart',
    description: 'Admin chart data',
    operations: [
        new Get(
            uriTemplate: '/admin/charts',
            provider: ChartDataProvider::class,
            openapi: new Model\Operation(summary: 'Get chart data for admin dashboard', tags: ['Administration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ChartResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'charts';

    /** @var array<string, mixed> */
    public array $registrations = [];

    /** @var array<string, mixed> */
    public array $activity = [];

    /** @var array<string, mixed> */
    public array $assetDistribution = [];
}
