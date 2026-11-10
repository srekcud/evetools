<?php

declare(strict_types=1);

namespace App\Enum;

enum ExtractorExpiryLevel: string
{
    case Warning = 'warning';
    case Critical = 'critical';
    case Expired = 'expired';

    public function isHigherThan(?self $other): bool
    {
        return $other === null || $this->rank() > $other->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Warning => 1,
            self::Critical => 2,
            self::Expired => 3,
        };
    }
}
