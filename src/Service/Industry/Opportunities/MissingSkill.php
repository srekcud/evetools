<?php

declare(strict_types=1);

namespace App\Service\Industry\Opportunities;

final readonly class MissingSkill
{
    public function __construct(
        public int $skillId,
        public ?string $name,
        public int $level,
    ) {
    }
}
