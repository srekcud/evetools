<?php

declare(strict_types=1);

namespace App\Industry\Application;

interface UserProductionStructures
{
    /**
     * @return list<ProductionStructure>
     */
    public function configuredBy(string $userId): array;
}
