<?php

declare(strict_types=1);

namespace App\ApiResource\Pve;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Pve\ResetDeclinedInput;
use App\State\Processor\Pve\ResetDeclinedProcessor;
use App\State\Provider\Pve\SettingsProvider;

#[ApiResource(
    shortName: 'PveSettings',
    description: 'PVE settings (ammo types, loot types)',
    operations: [
        new Get(
            uriTemplate: '/pve/settings',
            provider: SettingsProvider::class,
            openapi: new Model\Operation(summary: 'Get PVE settings', tags: ['Revenue - PVE']),
        ),
        new Post(
            uriTemplate: '/pve/settings/reset-declined',
            processor: ResetDeclinedProcessor::class,
            input: ResetDeclinedInput::class,
            output: SuccessResource::class,
            openapi: new Model\Operation(summary: 'Reset declined contracts/transactions', tags: ['Revenue - PVE']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class SettingsResource
{
    /** @var TypeResource[] */
    public array $ammoTypes = [];

    /** @var TypeResource[] */
    public array $lootTypes = [];

    public int $declinedContractsCount = 0;

    public int $declinedTransactionsCount = 0;
}
