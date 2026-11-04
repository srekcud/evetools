<?php

declare(strict_types=1);

namespace App\ApiResource\Mining;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\Mining\RecordMoonCompositionProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'MoonComposition',
    operations: [
        new Post(uriTemplate: '/mining/moon-compositions', processor: RecordMoonCompositionProcessor::class),
    ],
    security: "is_granted('ROLE_USER')",
)]
class MoonCompositionResource
{
    #[Assert\Positive]
    public int $corporationId = 0;

    #[Assert\Positive]
    public int $moonId = 0;

    #[ApiProperty(readable: false)]
    #[Assert\NotBlank]
    public string $scan = '';

    public ?\DateTimeImmutable $recordedAt = null;

    /** @var list<array{typeId: int, typeName: string, share: float}> */
    public array $lines = [];
}
