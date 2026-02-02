<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use App\ApiResource\Input\Pve\AddTypeInput;
use App\State\Processor\Pve\AddAmmoTypeProcessor;
use App\State\Processor\Pve\RemoveAmmoTypeProcessor;
use App\State\Provider\Pve\AmmoTypeProvider;

#[ApiResource(
    shortName: 'PveAmmoType',
    description: 'PVE ammo type settings',
    operations: [
        new Post(
            uriTemplate: '/pve/settings/ammo',
            processor: AddAmmoTypeProcessor::class,
            input: AddTypeInput::class,
            openapiContext: [
                'summary' => 'Add ammo type to settings',
            ],
        ),
        new Delete(
            uriTemplate: '/pve/settings/ammo/{id}',
            provider: AmmoTypeProvider::class,
            processor: RemoveAmmoTypeProcessor::class,
            openapiContext: [
                'summary' => 'Remove ammo type from settings',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AmmoTypeResource
{
    #[ApiProperty(identifier: true)]
    public int $id;

    public string $typeName = '';
}
