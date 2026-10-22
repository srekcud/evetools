<?php

declare(strict_types=1);

namespace App\Tests\Unit\Industry\Application\Double;

use App\Industry\Application\ProductionStructure;
use App\Industry\Application\UserProductionStructures;

final class InMemoryUserProductionStructures implements UserProductionStructures
{
    public array $requestedUserIds = [];

    public function __construct(private readonly array $structuresByUserId = [])
    {
    }

    public function configuredBy(string $userId): array
    {
        $this->requestedUserIds[] = $userId;

        return $this->structuresByUserId[$userId] ?? [];
    }
}
