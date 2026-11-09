<?php

declare(strict_types=1);

namespace App\Service\Industry\Skills;

final readonly class SkillGainReport
{
    /**
     * @param list<SkillGainLine> $lines
     */
    public function __construct(
        public ?float $iskPerHour,
        public array $lines,
    ) {
    }
}
