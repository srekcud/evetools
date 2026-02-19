<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Me\WalletProvider;

#[ApiResource(
    shortName: 'Wallet',
    description: 'Wallet balances for user characters',
    operations: [
        new Get(
            uriTemplate: '/me/wallets',
            provider: WalletProvider::class,
            openapi: new Model\Operation(summary: 'Get wallet balances', description: 'Returns ISK balances for all characters', tags: ['Account & Characters']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class WalletResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'current';

    /** @var WalletEntryResource[] */
    public array $wallets = [];

    public float $totalBalance = 0.0;
}
