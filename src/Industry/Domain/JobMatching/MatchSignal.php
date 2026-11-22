<?php

declare(strict_types=1);

namespace App\Industry\Domain\JobMatching;

enum MatchSignal: string
{
    case Intent = 'intent';
    case Place = 'place';
    case Structure = 'structure';
    case ExactFit = 'exact_fit';

    public function weight(): int
    {
        return match ($this) {
            self::Intent => 8,
            self::Place => 4,
            self::Structure => 2,
            self::ExactFit => 1,
        };
    }
}
