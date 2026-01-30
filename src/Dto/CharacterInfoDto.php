<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class CharacterInfoDto
{
    public function __construct(
        public int $characterId,
        public string $characterName,
        public int $corporationId,
        public string $corporationName,
        public ?int $allianceId = null,
        public ?string $allianceName = null,
    ) {
    }
}
