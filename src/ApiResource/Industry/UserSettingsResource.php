<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\UpdateUserSettingsInput;
use App\State\Processor\Industry\UpdateUserSettingsProcessor;
use App\State\Provider\Industry\UserSettingsProvider;

#[ApiResource(
    shortName: 'IndustryUserSettings',
    description: 'Industry user settings (favorite systems)',
    operations: [
        new Get(
            uriTemplate: '/industry/settings',
            provider: UserSettingsProvider::class,
            openapi: new Model\Operation(summary: 'Get industry settings', description: 'Returns industry user settings including favorite systems', tags: ['Industry - Configuration']),
        ),
        new Patch(
            uriTemplate: '/industry/settings',
            provider: UserSettingsProvider::class,
            processor: UpdateUserSettingsProcessor::class,
            input: UpdateUserSettingsInput::class,
            openapi: new Model\Operation(summary: 'Update industry settings', description: 'Updates industry user settings', tags: ['Industry - Configuration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class UserSettingsResource
{
    public ?int $favoriteManufacturingSystemId = null;

    public ?string $favoriteManufacturingSystemName = null;

    public ?int $favoriteReactionSystemId = null;

    public ?string $favoriteReactionSystemName = null;

    public float $brokerFeeRate = 0.036;

    public float $salesTaxRate = 0.036;
}
