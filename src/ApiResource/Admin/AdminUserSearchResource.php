<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Admin\AdminUserSearchProvider;

#[ApiResource(
    shortName: 'AdminUserSearch',
    description: 'Search users by character name or EVE character id (admin only)',
    operations: [
        new GetCollection(
            uriTemplate: '/admin/users',
            provider: AdminUserSearchProvider::class,
            paginationEnabled: false,
            openapi: new Model\Operation(summary: 'Search users by character name or EVE character id', tags: ['Administration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AdminUserSearchResource
{
    #[ApiProperty(identifier: true)]
    public string $userId = '';

    public ?string $mainCharacterName = null;

    public ?int $mainEveCharacterId = null;

    public int $characterCount = 0;

    public ?string $lastLoginAt = null;
}
