<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Me\SkillGainsProvider;

#[ApiResource(
    shortName: 'SkillGains',
    description: 'Monthly gain of the next level of each industry skill of a character',
    operations: [
        new Get(
            uriTemplate: '/me/characters/{characterId}/skill-gains',
            provider: SkillGainsProvider::class,
            openapi: new Model\Operation(
                summary: 'Get the skill gains of a character',
                description: 'Monthly gain and training duration of the next level of each skill, valued at the industry ISK/h of the last 30 days',
                tags: ['Account & Characters'],
            ),
        ),
    ],
    security: "is_granted('ROLE_USER')",
    normalizationContext: ['skip_null_values' => false],
)]
class SkillGainsResource
{
    #[ApiProperty(identifier: true)]
    public string $characterId = '';

    /** @var array{from: string, to: string, days: int} */
    public array $period = ['from' => '', 'to' => '', 'days' => 0];

    public ?float $iskPerHour = null;

    /** @var list<array<string, mixed>> */
    public array $lines = [];
}
