<?php

declare(strict_types=1);

namespace App\Enum;

enum MoonChunkStatus: string
{
    case Extracting = 'extracting';
    case Ready = 'ready';
    case Exploded = 'exploded';

    public static function fromTimes(\DateTimeImmutable $now, \DateTimeImmutable $chunkArrival, \DateTimeImmutable $naturalDecay): self
    {
        if ($now < $chunkArrival) {
            return self::Extracting;
        }

        return $now < $naturalDecay ? self::Ready : self::Exploded;
    }
}
