<?php

declare(strict_types=1);

namespace App\ApiResource\Admin;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\Dto\AdminStatusRule;
use App\State\Provider\Admin\AdminStatusProvider;

#[ApiResource(
    shortName: 'AdminStatus',
    description: 'Admin global status',
    operations: [
        new Get(
            uriTemplate: '/admin/status',
            provider: AdminStatusProvider::class,
            openapi: new Model\Operation(summary: 'Get the admin global status', tags: ['Administration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class AdminStatusResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'status';

    public string $level = 'ok';

    /** @var list<AdminStatusRule> */
    public array $rules = [];
}
