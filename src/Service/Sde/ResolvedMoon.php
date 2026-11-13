<?php

declare(strict_types=1);

namespace App\Service\Sde;

final readonly class ResolvedMoon
{
    public function __construct(
        public string $name,
        public int $solarSystemId,
    ) {
    }
}
