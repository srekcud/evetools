<?php

declare(strict_types=1);

namespace App\Service\Industry\Project;

final readonly class ProjectContainerStock
{
    /**
     * @param array<int, int> $quantitiesByTypeId
     * @param list<int>       $contentItemIds
     */
    public function __construct(
        public ?string $unknownReason,
        public array $quantitiesByTypeId = [],
        public array $contentItemIds = [],
    ) {
    }
}
