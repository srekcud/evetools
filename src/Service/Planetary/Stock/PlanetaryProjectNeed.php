<?php

declare(strict_types=1);

namespace App\Service\Planetary\Stock;

final readonly class PlanetaryProjectNeed
{
    /**
     * @param list<array{projectId: string, projectName: string|null, remaining: int}> $projects
     */
    public function __construct(
        public int $typeId,
        public ?string $typeName,
        public int $required,
        public ?int $stock,
        public ?int $missing,
        public array $projects,
    ) {
    }
}
