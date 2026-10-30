<?php

declare(strict_types=1);

namespace App\Service\Pve;

final readonly class CorpProjectContribution
{
    public const UNTYPED_LABEL = 'Items';

    public function __construct(
        public string $projectId,
        public string $projectName,
        public string $corporationName,
        public int $characterId,
        public string $characterName,
        public int $typeId,
        public int $contributed,
        public int $accountedQuantity,
        public ?float $rewardPerContribution,
        public \DateTimeImmutable $contributedAt,
    ) {
    }

    public function pendingQuantity(): int
    {
        return $this->contributed - $this->accountedQuantity;
    }

    public function pendingValue(): ?float
    {
        return $this->rewardPerContribution === null
            ? null
            : $this->pendingQuantity() * $this->rewardPerContribution;
    }

    public function trackingId(): int
    {
        return -abs(crc32("{$this->projectId}_{$this->characterId}_{$this->contributed}"));
    }
}
