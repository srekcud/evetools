<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\ApiResource\Input\EmptyInput;
use App\ApiResource\Input\Mining\ReprocessingProfileInput;
use App\State\Processor\Mining\RefreshReprocessingProfileProcessor;
use App\State\Processor\Mining\SaveReprocessingProfileProcessor;
use App\State\Provider\Mining\ReprocessingProfileProvider;

#[ApiResource(
    shortName: 'ReprocessingProfile',
    operations: [
        new Get(
            uriTemplate: '/mining/reprocessing-profile',
            provider: ReprocessingProfileProvider::class,
        ),
        new Put(
            uriTemplate: '/mining/reprocessing-profile',
            read: false,
            processor: SaveReprocessingProfileProcessor::class,
            input: ReprocessingProfileInput::class,
        ),
        new Post(
            uriTemplate: '/mining/reprocessing-profile/refresh',
            processor: RefreshReprocessingProfileProcessor::class,
            input: EmptyInput::class,
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class ReprocessingProfileResource
{
    public bool $configured = false;

    public ?int $characterId = null;

    public ?int $oreProcessingSkillTypeId = null;

    public ?string $location = null;

    public ?string $rigTier = null;

    public ?string $security = null;

    public ?int $implantPercent = null;

    public ?int $reprocessingLevel = null;

    public ?int $reprocessingEfficiencyLevel = null;

    public ?int $oreProcessingLevel = null;

    public ?float $reprocessYield = null;

    public ?string $failure = null;

    public ?\DateTimeImmutable $computedAt = null;

    /** @var list<array{typeId: int, typeName: string}> */
    public array $oreProcessingSkills = [];
}
