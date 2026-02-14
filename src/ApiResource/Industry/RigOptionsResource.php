<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Industry\RigOptionsProvider;

#[ApiResource(
    shortName: 'RigOptions',
    description: 'Industry rig options',
    operations: [
        new Get(
            uriTemplate: '/industry/structures/rig-options',
            provider: RigOptionsProvider::class,
            openapi: new Model\Operation(summary: 'Get rig options', description: 'Returns available rig options for structures'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class RigOptionsResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'rig-options';

    /** @var array<array{name: string, bonus: float, category: string, size: string, targetCategories: string[]}> */
    public array $manufacturing = [];

    /** @var array<array{name: string, bonus: float, category: string, size: string, targetCategories: string[]}> */
    public array $reaction = [];
}
