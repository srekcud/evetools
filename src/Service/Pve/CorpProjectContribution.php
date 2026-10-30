<?php

declare(strict_types=1);

namespace App\Service\Pve;

final readonly class CorpProjectContribution
{
    public function __construct(
        public string $projectId,
        public string $projectName,
        public string $corporationName,
        public int $characterId,
        public string $characterName,
        public int $typeId,
        public int $contributed,
        public ?float $rewardPerContribution,
        public \DateTimeImmutable $contributedAt,
    ) {
    }
}
