<?php

declare(strict_types=1);

namespace App\ApiResource\Industry\Project;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Industry\Project\UpdateLineRentalRatesInput;
use App\State\Processor\Industry\Project\UpdateLineRentalRatesProcessor;
use App\State\Provider\Industry\Project\LineRentalRatesProvider;

#[ApiResource(
    shortName: 'LineRentalRates',
    description: 'Default line rental rates for group industry projects',
    operations: [
        new Get(
            uriTemplate: '/industry/line-rental-rates',
            provider: LineRentalRatesProvider::class,
            openapi: new Model\Operation(
                summary: 'Get line rental rates',
                description: 'Returns the user default line rental rates per category',
                tags: ['Group Industry - Settings'],
            ),
        ),
        new Patch(
            uriTemplate: '/industry/line-rental-rates',
            provider: LineRentalRatesProvider::class,
            processor: UpdateLineRentalRatesProcessor::class,
            input: UpdateLineRentalRatesInput::class,
            openapi: new Model\Operation(
                summary: 'Update line rental rates',
                description: 'Updates the user default line rental rates',
                tags: ['Group Industry - Settings'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class LineRentalRatesResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'default';

    /** @var array<string, int> */
    public array $rates = [];
}
