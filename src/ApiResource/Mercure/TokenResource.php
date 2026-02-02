<?php

declare(strict_types=1);

namespace App\ApiResource\Mercure;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\Mercure\TokenProvider;

#[ApiResource(
    shortName: 'MercureToken',
    description: 'JWT token for Mercure subscriptions',
    operations: [
        new Get(
            uriTemplate: '/mercure/token',
            provider: TokenProvider::class,
            openapiContext: [
                'summary' => 'Get Mercure subscription token',
                'description' => 'Returns a JWT token for subscribing to Mercure topics',
            ],
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class TokenResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'current';

    public string $token;

    /** @var string[] */
    public array $topics;

    public string $hubUrl;
}
