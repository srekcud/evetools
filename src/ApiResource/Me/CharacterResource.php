<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\State\Processor\Me\DeleteCharacterProcessor;
use App\State\Provider\Me\CharacterDeleteProvider;
use App\State\Processor\Me\SetMainCharacterProcessor;
use App\State\Provider\Me\CharacterCollectionProvider;

#[ApiResource(
    shortName: 'Character',
    description: 'EVE Online characters linked to the user',
    operations: [
        new GetCollection(
            uriTemplate: '/me/characters',
            provider: CharacterCollectionProvider::class,
            openapi: new Model\Operation(summary: 'List user characters', description: 'Returns all characters linked to the authenticated user'),
        ),
        new Delete(
            uriTemplate: '/me/characters/{id}',
            provider: CharacterDeleteProvider::class,
            processor: DeleteCharacterProcessor::class,
            openapi: new Model\Operation(summary: 'Delete a character', description: 'Unlinks a character from the user account (cannot delete main character)'),
        ),
        new Post(
            uriTemplate: '/me/characters/{id}/set-main',
            processor: SetMainCharacterProcessor::class,
            input: EmptyInput::class,
            output: CharacterResource::class,
            openapi: new Model\Operation(summary: 'Set main character', description: 'Sets a character as the main character for the user'),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CharacterResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public int $eveCharacterId;

    public string $name;

    public int $corporationId;

    public string $corporationName;

    public ?int $allianceId = null;

    public ?string $allianceName = null;

    public bool $isMain = false;

    public bool $hasValidToken = false;

    public bool $hasMissingScopes = false;

    public ?string $lastSyncAt = null;
}
