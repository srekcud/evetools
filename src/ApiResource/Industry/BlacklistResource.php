<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\Industry\UpdateBlacklistInput;
use App\State\Processor\Industry\UpdateBlacklistProcessor;
use App\State\Provider\Industry\BlacklistProvider;

#[ApiResource(
    shortName: 'IndustryBlacklist',
    description: 'Industry production blacklist',
    operations: [
        new Get(
            uriTemplate: '/industry/blacklist',
            provider: BlacklistProvider::class,
            openapiContext: [
                'summary' => 'Get blacklist',
                'description' => 'Returns categories and items blacklisted from production',
            ],
        ),
        new Put(
            uriTemplate: '/industry/blacklist',
            processor: UpdateBlacklistProcessor::class,
            input: UpdateBlacklistInput::class,
            openapiContext: [
                'summary' => 'Update blacklist',
                'description' => 'Updates the blacklist groups and types',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class BlacklistResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'blacklist';

    /** @var array<array{groupId: int, groupName: string, categoryName: string, enabled: bool}> */
    public array $categories = [];

    /** @var array<array{typeId: int, typeName: string}> */
    public array $items = [];
}
