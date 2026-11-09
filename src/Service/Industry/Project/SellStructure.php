<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class SellStructure
{
    public function __construct(
        public int $id,
        public string $name,
    ) {
    }
}
