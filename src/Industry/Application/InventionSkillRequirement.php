<?php

declare(strict_types=1);

namespace App\Industry\Application;

final readonly class InventionSkillRequirement
{
    /**
     * @param list<int> $scienceSkillTypeIds
     */
    public function __construct(
        public array $scienceSkillTypeIds,
        public ?int $encryptionSkillTypeId,
    ) {
    }
}
