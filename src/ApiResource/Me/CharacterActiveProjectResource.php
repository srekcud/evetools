<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\Me\SetCharacterActiveProjectInput;
use App\State\Processor\Me\SetCharacterActiveProjectProcessor;
use App\State\Provider\Me\CharacterActiveProjectProvider;

#[ApiResource(
    shortName: 'CharacterActiveProject',
    description: 'Open project a character is dedicated to for job matching',
    operations: [
        new Patch(
            uriTemplate: '/me/characters/{id}/active-project',
            uriVariables: [
                'id' => new Link(fromClass: self::class),
            ],
            provider: CharacterActiveProjectProvider::class,
            processor: SetCharacterActiveProjectProcessor::class,
            input: SetCharacterActiveProjectInput::class,
            normalizationContext: ['skip_null_values' => false],
            openapi: new Model\Operation(
                summary: 'Dedicate a character to a project',
                description: 'Sets or clears the active project of a character of the user among the open projects the user takes part in',
                tags: ['Account & Characters'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CharacterActiveProjectResource
{
    #[ApiProperty(identifier: true)]
    public string $id;

    public ?string $activeProjectId = null;

    public ?string $activeProjectName = null;
}
