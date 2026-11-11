<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Admin\AdminUserSheetProvider;

#[ApiResource(
    shortName: 'AdminUserSheet',
    description: 'Support sheet of one user (admin only)',
    operations: [
        new Get(
            uriTemplate: '/admin/users/{userId}',
            provider: AdminUserSheetProvider::class,
            openapi: new Model\Operation(summary: 'Get the support sheet of a user', tags: ['Administration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AdminUserSheetResource
{
    #[ApiProperty(identifier: true)]
    public string $userId = '';

    public string $createdAt = '';

    public ?string $lastLoginAt = null;

    /** @var AdminUserSheetCharacterDto[] */
    public array $characters = [];

    /** @var AdminUserSheetSyncDto[] */
    public array $syncs = [];
}

class AdminUserSheetCharacterDto
{
    /**
     * @param list<string> $missingScopes
     */
    public function __construct(
        public int $eveCharacterId = 0,
        public string $name = '',
        public bool $isMain = false,
        public string $tokenState = '',
        public ?string $accessTokenExpiresAt = null,
        public array $missingScopes = [],
        public ?string $lastSyncAt = null,
    ) {
    }
}

class AdminUserSheetSyncDto
{
    public function __construct(
        public string $type = '',
        public ?string $lastDataAt = null,
        public bool $triggerable = false,
    ) {
    }
}
