<?php

declare(strict_types=1);

namespace App\ApiResource\Industry;

class CharacterSkillCollectionResource
{
    /** @var CharacterSkillResource[] */
    public array $characters = [];

    public int $syncedCount = 0;

    /** @var list<array{characterName: string, reason: string, statusCode: int|null}> */
    public array $failedCharacters = [];
}
