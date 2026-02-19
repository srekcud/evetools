<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\Industry\UpdateCharacterSkillInput;
use App\State\Processor\Industry\SyncCharacterSkillsProcessor;
use App\State\Processor\Industry\UpdateCharacterSkillProcessor;
use App\State\Provider\Industry\CharacterSkillCollectionProvider;

#[ApiResource(
    shortName: 'IndustryCharacterSkill',
    description: 'Industry character skills',
    operations: [
        new Get(
            uriTemplate: '/industry/character-skills',
            provider: CharacterSkillCollectionProvider::class,
            output: CharacterSkillCollectionResource::class,
            openapi: new Model\Operation(summary: 'List character skills', description: 'Returns industry skills for all characters', tags: ['Industry - Configuration']),
        ),
        new Post(
            uriTemplate: '/industry/character-skills/sync',
            processor: SyncCharacterSkillsProcessor::class,
            input: EmptyInput::class,
            output: CharacterSkillCollectionResource::class,
            openapi: new Model\Operation(summary: 'Sync skills from ESI', description: 'Syncs industry skills from ESI for all characters', tags: ['Industry - Configuration']),
        ),
        new Patch(
            uriTemplate: '/industry/character-skills/{characterId}',
            processor: UpdateCharacterSkillProcessor::class,
            input: UpdateCharacterSkillInput::class,
            openapi: new Model\Operation(summary: 'Update character skills', description: 'Manually update industry skill levels for a character', tags: ['Industry - Configuration']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class CharacterSkillResource
{
    #[ApiProperty(identifier: true)]
    public string $characterId;

    public string $characterName;

    public int $industry = 0;

    public int $advancedIndustry = 0;

    public int $reactions = 0;

    public string $source = 'manual';

    public ?string $lastSyncAt = null;
}
