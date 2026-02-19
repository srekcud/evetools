<?php

declare(strict_types=1);

namespace App\ApiResource\Me;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model;
use App\State\Provider\Me\SkillQueueProvider;

#[ApiResource(
    shortName: 'SkillQueue',
    description: 'Skill training queue for user characters',
    operations: [
        new Get(
            uriTemplate: '/me/skillqueues',
            provider: SkillQueueProvider::class,
            openapi: new Model\Operation(summary: 'Get skill queues', description: 'Returns currently training skills for all characters', tags: ['Account & Characters']),
        ),
    ],
    security: "is_granted('ROLE_USER')",
)]
class SkillQueueResource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'current';

    /** @var SkillQueueEntryResource[] */
    public array $skillQueues = [];
}
