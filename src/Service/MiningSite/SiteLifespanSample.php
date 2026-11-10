<?php

declare(strict_types=1);

namespace App\Service\MiningSite;

final readonly class SiteLifespanSample
{
    public function __construct(
        public string $label,
        public \DateTimeImmutable $firstScanAt,
        public float $lifespanMinutes,
        public int $miners,
    ) {
    }

    public function workMinerMinutes(): ?float
    {
        return $this->miners > 0 ? $this->miners * $this->lifespanMinutes : null;
    }
}
