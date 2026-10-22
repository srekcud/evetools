<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Processor\Me\DeleteAccountProcessor;
use App\State\Provider\Me\AccountDeleteProvider;
use App\State\Provider\Me\UserProvider;

#[ApiResource(
    shortName: 'User',
    description: 'Current authenticated user information',
    operations: [
        new Get(
            uriTemplate: '/me',
            provider: UserProvider::class,
            openapi: new Model\Operation(summary: 'Get current user', description: 'Returns the authenticated user with their characters', tags: ['Account & Characters']),
        ),
        new Delete(
            uriTemplate: '/me',
            provider: AccountDeleteProvider::class,
            processor: DeleteAccountProcessor::class,
            openapi: new Model\Operation(summary: 'Delete the current account', description: 'Deletes the authenticated user, their characters and personal data; collaborative contributions and sales are kept anonymized', tags: ['Account & Characters']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class UserResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public string $authStatus;

    #[ApiProperty(readableLink: true)]
    public ?CharacterResource $mainCharacter = null;

    /** @var CharacterResource[] */
    #[ApiProperty(readableLink: true)]
    public array $characters = [];

    public ?int $corporationId = null;

    public ?string $corporationName = null;

    public ?int $allianceId = null;

    public ?string $allianceName = null;

    public string $createdAt;

    public ?string $lastLoginAt = null;
}
