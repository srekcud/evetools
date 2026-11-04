<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\Mining\RecordMoonCompositionProcessor;

#[ApiResource(
    shortName: 'MoonComposition',
    operations: [
        new Post(uriTemplate: '/mining/moon-compositions', processor: RecordMoonCompositionProcessor::class),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MoonCompositionResource
{
    public int $corporationId;

    public int $moonId;

    #[ApiProperty(readable: false)]
    public string $scan;

    public ?\DateTimeImmutable $recordedAt = null;

    /** @var list<array{typeId: int, typeName: string, share: float}> */
    public array $lines = [];
}
