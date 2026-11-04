<?php

declare(strict_types=1);

namespace App\Service\Sync;

use App\Enum\MoonTaxSyncFailure;

final readonly class MoonTaxReadOutcome
{
    private function __construct(
        public mixed $value,
        public ?MoonTaxSyncFailure $failure,
    ) {
    }

    public static function read(mixed $value): self
    {
        return new self($value, null);
    }

    public static function failed(MoonTaxSyncFailure $failure): self
    {
        return new self(null, $failure);
    }
}
