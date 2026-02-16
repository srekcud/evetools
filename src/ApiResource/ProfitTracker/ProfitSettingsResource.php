<?php

declare(strict_types=1);

namespace App\ApiResource\ProfitTracker;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\State\Processor\ProfitTracker\UpdateProfitSettingsProcessor;
use App\State\Provider\ProfitTracker\ProfitSettingsProvider;

#[ApiResource(
    shortName: 'ProfitSettings',
    description: 'Profit tracker user settings',
    operations: [
        new Get(
            uriTemplate: '/profit-tracker/settings',
            provider: ProfitSettingsProvider::class,
            openapi: new Model\Operation(summary: 'Get profit tracker settings'),
        ),
        new Patch(
            uriTemplate: '/profit-tracker/settings',
            provider: ProfitSettingsProvider::class,
            processor: UpdateProfitSettingsProcessor::class,
            openapi: new Model\Operation(
                summary: 'Update profit tracker settings',
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'application/merge-patch+json' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'salesTaxRate' => [
                                        'type' => 'number',
                                        'format' => 'float',
                                        'description' => 'Sales tax rate (e.g., 0.036 for 3.6%)',
                                    ],
                                    'defaultCostSource' => [
                                        'type' => 'string',
                                        'enum' => ['market', 'project', 'manual'],
                                        'description' => 'Default cost estimation source',
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ),
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ProfitSettingsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'settings';

    public float $salesTaxRate = 0.036;

    public string $defaultCostSource = 'market';

    public string $updatedAt = '';
}
