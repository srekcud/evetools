<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use ApiPlatform\OpenApi\Model;
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
            openapi: new Model\Operation(summary: 'Get blacklist', description: 'Returns categories and items blacklisted from production'),
        ),
        new Put(
            uriTemplate: '/industry/blacklist',
            processor: UpdateBlacklistProcessor::class,
            input: UpdateBlacklistInput::class,
            openapi: new Model\Operation(summary: 'Update blacklist', description: 'Updates the blacklist groups and types'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class BlacklistResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'blacklist';

    /** @var list<array<string, mixed>> */
    public array $categories = [];

    /** @var list<array<string, mixed>> */
    public array $items = [];
}
