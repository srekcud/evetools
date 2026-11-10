<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class BreakEven
{
    public function __construct(
        public ?float $perUnit,
        public ?string $unknownReason,
    ) {
    }

    public static function known(float $perUnit): self
    {
        return new self($perUnit, null);
    }

    public static function unknown(string $reason): self
    {
        return new self(null, $reason);
    }
}
