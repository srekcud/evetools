<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Pve\AddTypeInput;
use App\State\Processor\Pve\AddLootTypeProcessor;
use App\State\Processor\Pve\RemoveLootTypeProcessor;
use App\State\Provider\Pve\LootTypeProvider;

#[ApiResource(
    shortName: 'PveLootType',
    description: 'PVE loot type settings',
    operations: [
        new Post(
            uriTemplate: '/pve/settings/loot',
            processor: AddLootTypeProcessor::class,
            input: AddTypeInput::class,
            openapiContext: [
                'summary' => 'Add loot type to settings',
            ],
        ),
        new Delete(
            uriTemplate: '/pve/settings/loot/{id}',
            provider: LootTypeProvider::class,
            processor: RemoveLootTypeProcessor::class,
            openapiContext: [
                'summary' => 'Remove loot type from settings',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class LootTypeResource
{
    #[ApiProperty(identifier: true)]
    public int $id;

    public string $typeName = '';
}
